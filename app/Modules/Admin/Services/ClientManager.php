<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Models\PlatformAdmin;
use App\Modules\Admin\Models\PlatformOrganization;
use App\Modules\Admin\Models\PlatformPlan;
use App\Modules\Admin\Notifications\AccountStatusNotification;
use App\Modules\Admin\Support\AdminAccess;
use App\Modules\Billing\Models\Plan;
use App\Modules\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Actions du Super Admin sur la fiche d'un client (docs/31) : modifier les informations, prolonger l'essai, étiqueter, noter.
 * Chaque action est écrite avec le rôle `nova_platform` et consignée dans le journal d'audit (avant / après quand c'est utile). Les
 * transitions de compte (suspension, levée, suppression) restent dans `OrganizationLifecycle`.
 *
 * Volontairement absent : tout ce qui touche à l'argent d'un client (remise, remboursement, paiement marqué reçu, relance de paiement).
 * Cela demande des décisions de facturation non prises (docs/10, docs/31 §10). Seule exception, décidée par le fondateur (2026-11-02,
 * aucun prestataire de paiement encore branché) : `grantPlan()` accorde une formule sans paiement, pour les essais et démonstrations.
 */
final class ClientManager
{
    /** Champs de la fiche que l'équipe peut corriger. */
    public const DETAIL_FIELDS = ['name', 'billing_name', 'billing_vat_number', 'country', 'timezone', 'locale', 'sector'];

    public const ADDRESS_FIELDS = ['street', 'number', 'postal_code', 'city', 'country'];

    /** Durée maximale d'une prolongation d'essai (jours). */
    public const MAX_EXTENSION_DAYS = 60;

    /** Statuts de compte depuis lesquels on peut prolonger l'essai : en essai, ou suspendu faute d'abonnement à la fin de l'essai. */
    public static function canExtendTrial(PlatformOrganization $org): bool
    {
        return $org->status === Organization::STATUS_TRIAL || ($org->status === Organization::STATUS_SUSPENDED && $org->suspension_reason === 'system.trial_ended');
    }

    /** Ouverture d'une fiche : la consultation de données client est tracée. */
    public function viewed(PlatformAdmin $admin, PlatformOrganization $org): void
    {
        AdminAccess::authorize($admin, 'clients.view');
        PlatformAudit::record($admin->id, 'client.viewed', $org->id, 'organizations', $org->id, ['name' => $org->name]);
    }

    /**
     * Corrige les informations du client (nom, identité de facturation, adresse, pays, fuseau, langue, secteur).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, array{0: mixed, 1: mixed}> ce qui a changé : ancien et nouveau
     *
     * @throws InvalidArgumentException valeur invalide
     */
    public function updateDetails(PlatformAdmin $admin, PlatformOrganization $org, array $data): array
    {
        AdminAccess::authorize($admin, 'clients.edit');
        $before = $this->snapshot($org);
        $clean = [];

        foreach (self::DETAIL_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $clean[$field] = $this->cleanText($data[$field], $field === 'name');
            }
        }

        if (isset($clean['locale']) && ! in_array($clean['locale'], (array) config('rendivoo.locales'), true)) {
            throw new InvalidArgumentException('Langue inconnue.');
        }

        if (isset($clean['timezone']) && ! in_array($clean['timezone'], timezone_identifiers_list(), true)) {
            throw new InvalidArgumentException('Fuseau horaire inconnu.');
        }

        if (isset($clean['country']) && ! preg_match('/^[A-Za-z]{2}$/', (string) $clean['country'])) {
            throw new InvalidArgumentException('Pays : code à deux lettres attendu.');
        }

        if (array_key_exists('address', $data)) {
            $address = [];

            foreach (self::ADDRESS_FIELDS as $field) {
                $value = $this->cleanText(((array) $data['address'])[$field] ?? null, false);

                if ($value !== null) {
                    $address[$field] = $value;
                }
            }

            $clean['billing_address'] = $address === [] ? null : $address;
        }

        if (isset($clean['country'])) {
            $clean['country'] = mb_strtoupper((string) $clean['country']);
        }

        DB::connection(PlatformAudit::CONNECTION)->transaction(function () use ($org, $clean): void {
            $org->forceFill($clean)->save();
        });

        $after = $this->snapshot($org->refresh());
        $changes = [];

        foreach ($after as $field => $value) {
            if ($value !== $before[$field]) {
                $changes[$field] = [$before[$field], $value];
            }
        }

        if ($changes !== []) {
            PlatformAudit::record($admin->id, 'client.updated', $org->id, 'organizations', $org->id, $changes);
        }

        return $changes;
    }

    /**
     * Prolonge l'essai de N jours à partir de la fin d'essai (ou de maintenant si elle est passée). Un compte suspendu faute d'abonnement à la
     * fin de l'essai redevient « essai ». Le propriétaire est prévenu par e-mail.
     *
     * @throws InvalidArgumentException statut ou durée non permis
     */
    public function extendTrial(PlatformAdmin $admin, PlatformOrganization $org, int $days, ?string $reason): CarbonImmutable
    {
        AdminAccess::authorize($admin, 'subscriptions.edit');
        if (! self::canExtendTrial($org)) {
            throw new InvalidArgumentException('L\'essai de ce compte ne peut pas être prolongé.');
        }

        if ($days < 1 || $days > self::MAX_EXTENSION_DAYS) {
            throw new InvalidArgumentException('Durée de prolongation hors limites.');
        }

        $from = $org->trial_ends_at !== null && $org->trial_ends_at->isFuture() ? CarbonImmutable::instance($org->trial_ends_at) : CarbonImmutable::now();
        $end = $from->addDays($days);
        $previousStatus = $org->status;
        $previousEnd = $org->trial_ends_at;

        DB::connection(PlatformAudit::CONNECTION)->transaction(function () use ($org, $end, $admin, $days, $reason, $previousStatus, $previousEnd): void {
            $org->forceFill([
                'status' => Organization::STATUS_TRIAL,
                'trial_ends_at' => $end,
                'trial_reminder_sent_at' => null,
                'previous_status' => null,
                'suspended_at' => null,
                'suspension_reason' => null,
            ])->save();

            PlatformAudit::record($admin->id, 'client.trial_extended', $org->id, 'organizations', $org->id, [
                'days' => $days, 'trial_ends_at' => [$previousEnd?->toIso8601String(), $end->toIso8601String()], 'status' => [$previousStatus, Organization::STATUS_TRIAL],
            ], $reason);
        });

        $this->notifyOwner($org, 'organization.trial_extended', $reason, $end);

        return $end;
    }

    /** Statuts de compte auxquels on peut accorder une formule : essai, actif, impayé, ou suspendu par le système (essai terminé, impayé). */
    public static function canGrantPlan(PlatformOrganization $org): bool
    {
        return in_array($org->status, [Organization::STATUS_TRIAL, Organization::STATUS_ACTIVE, Organization::STATUS_PAST_DUE], true)
            || ($org->status === Organization::STATUS_SUSPENDED && str_starts_with((string) $org->suspension_reason, 'system.'));
    }

    /**
     * Accorde une formule SANS paiement (essais, démonstrations, geste commercial : aucun prestataire de paiement n'est encore branché).
     * Le compte devient actif avec les limites de cette formule ; aucun abonnement ni facture n'est créé, donc aucun prélèvement ni
     * renouvellement. Refusé si le client a un abonnement en cours (son changement de formule passe par sa page Facturation).
     *
     * @throws InvalidArgumentException statut, formule ou abonnement en cours
     */
    public function grantPlan(PlatformAdmin $admin, PlatformOrganization $org, string $planId, ?string $reason): void
    {
        AdminAccess::authorize($admin, 'subscriptions.edit');

        if (! self::canGrantPlan($org)) {
            throw new InvalidArgumentException('Ce compte ne peut pas recevoir de formule dans son état actuel.');
        }

        $db = DB::connection(PlatformAudit::CONNECTION);
        $plan = PlatformPlan::query()->where('status', Plan::ACTIVE)->whereNull('superseded_by')->whereIn('plan_type', Plan::BILLABLE_TYPES)->find($planId)
            ?? throw new InvalidArgumentException('Formule inconnue ou indisponible.');

        if ($db->table('subscriptions')->where('org_id', $org->id)->whereIn('status', ['active', 'past_due'])->exists()) {
            throw new InvalidArgumentException('Ce client a un abonnement en cours.');
        }

        $previousPlan = $org->plan_id;
        $previousStatus = $org->status;

        $db->transaction(function () use ($org, $plan, $admin, $reason, $previousPlan, $previousStatus): void {
            $org->forceFill([
                'plan_id' => $plan->id, 'status' => Organization::STATUS_ACTIVE, 'previous_status' => null, 'suspended_at' => null, 'suspension_reason' => null,
            ])->save();

            PlatformAudit::record($admin->id, 'client.plan_granted', $org->id, 'organizations', $org->id, [
                'plan' => $plan->code, 'plan_id' => [$previousPlan, $plan->id], 'status' => [$previousStatus, Organization::STATUS_ACTIVE], 'billed' => false,
            ], $reason);
        });
    }

    /**
     * Ajoute une étiquette interne (30 caractères, minuscules, sans espace). Sans effet si elle existe déjà.
     *
     * @throws InvalidArgumentException étiquette vide
     */
    public function addTag(PlatformAdmin $admin, PlatformOrganization $org, string $tag): void
    {
        AdminAccess::authorize($admin, 'clients.edit');
        $clean = self::cleanTag($tag);

        if ($clean === '') {
            throw new InvalidArgumentException('Étiquette vide.');
        }

        $added = DB::connection(PlatformAudit::CONNECTION)->table('client_tags')->insertOrIgnore(['id' => (string) Str::uuid7(), 'org_id' => $org->id, 'tag' => $clean, 'created_by' => $admin->id, 'created_at' => now()]);

        if ($added > 0) {
            PlatformAudit::record($admin->id, 'client.tag_added', $org->id, 'organizations', $org->id, ['tag' => $clean]);
        }
    }

    public function removeTag(PlatformAdmin $admin, PlatformOrganization $org, string $tag): void
    {
        AdminAccess::authorize($admin, 'clients.edit');
        $clean = self::cleanTag($tag);

        if (DB::connection(PlatformAudit::CONNECTION)->table('client_tags')->where('org_id', $org->id)->where('tag', $clean)->delete() > 0) {
            PlatformAudit::record($admin->id, 'client.tag_removed', $org->id, 'organizations', $org->id, ['tag' => $clean]);
        }
    }

    /**
     * Note interne (jamais visible du client). Le journal garde la longueur, pas le texte.
     *
     * @throws InvalidArgumentException note vide
     */
    public function addNote(PlatformAdmin $admin, PlatformOrganization $org, string $body): void
    {
        AdminAccess::authorize($admin, 'clients.edit');
        $text = trim($body);

        if ($text === '') {
            throw new InvalidArgumentException('Note vide.');
        }

        DB::connection(PlatformAudit::CONNECTION)->table('client_notes')->insert(['id' => (string) Str::uuid7(), 'org_id' => $org->id, 'author_id' => $admin->id, 'body' => mb_substr($text, 0, 5000), 'created_at' => now()]);
        PlatformAudit::record($admin->id, 'client.note_added', $org->id, 'organizations', $org->id, ['length' => mb_strlen($text)]);
    }

    /**
     * Applique la même action à plusieurs clients ; ceux qui la refusent (statut, durée…) sont comptés « ignorés » sans bloquer les autres.
     *
     * @param  iterable<string>  $ids
     * @param  callable(PlatformOrganization): mixed  $action
     * @return array{done: int, skipped: int}
     */
    public function bulk(PlatformAdmin $admin, iterable $ids, string $name, callable $action): array
    {
        $done = 0;
        $skipped = 0;

        foreach ($ids as $id) {
            $org = PlatformOrganization::query()->find((string) $id);

            if ($org === null) {
                $skipped++;

                continue;
            }

            try {
                $action($org);
                $done++;
            } catch (InvalidArgumentException) {
                $skipped++;
            }
        }

        PlatformAudit::record($admin->id, 'client.bulk', null, 'organizations', null, ['operation' => $name, 'done' => $done, 'skipped' => $skipped]);

        return ['done' => $done, 'skipped' => $skipped];
    }

    public static function cleanTag(string $tag): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', '-', mb_strtolower(trim($tag)))), 0, 30);
    }

    private function cleanText(mixed $value, bool $required): ?string
    {
        $text = trim((string) $value);

        if ($text === '' && $required) {
            throw new InvalidArgumentException('Champ obligatoire vide.');
        }

        return $text === '' ? null : mb_substr($text, 0, 255);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(PlatformOrganization $org): array
    {
        return [
            'name' => $org->name, 'billing_name' => $org->billing_name, 'billing_vat_number' => $org->billing_vat_number, 'billing_address' => $org->billing_address,
            'country' => $org->country, 'timezone' => $org->timezone, 'locale' => $org->locale, 'sector' => $org->sector,
        ];
    }

    private function notifyOwner(PlatformOrganization $org, string $action, ?string $reason, CarbonImmutable $trialEnd): void
    {
        $owner = DB::connection(PlatformAudit::CONNECTION)->selectOne('select * from org_owner_contact(?)', [$org->id]);

        if ($owner === null) {
            return;
        }

        Notification::route('mail', (string) $owner->email)->notify(new AccountStatusNotification($action, $org->name, $reason, null, (string) $owner->locale, $trialEnd));
    }
}
