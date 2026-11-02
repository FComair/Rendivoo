<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Support;

use App\Modules\Admin\Models\PlatformAdmin;
use App\Modules\Admin\Models\PlatformOrganization;
use App\Modules\Admin\Models\PlatformPlan;
use App\Modules\Admin\Services\ClientExport;
use App\Modules\Admin\Services\ClientManager;
use App\Modules\Admin\Support\AdminAccess;
use App\Modules\Admin\Support\SupportAccess;
use App\Modules\Billing\Models\Plan;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Actions communes de la liste et de la fiche d'un client (docs/31) : modifier, prolonger l'essai, étiqueter, noter, exporter.
 * Réservées au Super Admin (un compte à rôle restreint n'a pas accès aux clients) ; un refus de règle devient un message clair.
 */
final class ClientActions
{
    public static function admin(): PlatformAdmin
    {
        $admin = SupportAccess::admin();

        abort_unless($admin !== null && ! SupportAccess::isRestricted($admin), 403);

        return $admin;
    }

    /**
     * Motif facultatif saisi dans une fenêtre d'action.
     *
     * @param  array<string, mixed>  $data
     */
    public static function reason(array $data): ?string
    {
        $reason = trim((string) ($data['reason'] ?? ''));

        return $reason === '' ? null : mb_substr($reason, 0, 500);
    }

    public static function organization(Model $record): PlatformOrganization
    {
        return PlatformOrganization::query()->findOrFail($record->getKey());
    }

    /**
     * Exécute une action ; renvoie vrai si elle a abouti. Une valeur refusée (durée, statut, champ vide…) devient une notification.
     *
     * @param  callable(PlatformAdmin): mixed  $operation
     */
    public static function perform(callable $operation, string $success): bool
    {
        try {
            $operation(self::admin());
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title(__('clients.errors.title'))->body(__('clients.errors.refused'))->persistent()->send();

            return false;
        } catch (AuthorizationException) {
            Notification::make()->danger()->title(__('admins.errors.title'))->body(__('admins.errors.forbidden'))->send();

            return false;
        }

        Notification::make()->success()->title($success)->send();

        return true;
    }

    /**
     * @param  array{done: int, skipped: int}  $result
     */
    public static function bulkResult(array $result): void
    {
        $notification = Notification::make()->title(__('clients.bulk.done', ['count' => $result['done']]));
        $result['skipped'] > 0 ? $notification->warning()->body(__('clients.bulk.skipped', ['count' => $result['skipped']])) : $notification->success();
        $notification->send();
    }

    public static function editDetails(): Action
    {
        return Action::make('edit_details')->visible(fn (): bool => AdminAccess::can('clients.edit'))->label(__('clients.actions.edit'))->icon('heroicon-m-pencil-square')->color('gray')->modalHeading(__('clients.edit.heading'))
            ->fillForm(function (Model $record): array {
                $org = self::organization($record);

                return [
                    'name' => $org->name, 'billing_name' => $org->billing_name, 'billing_vat_number' => $org->billing_vat_number, 'country' => $org->country,
                    'timezone' => $org->timezone, 'locale' => $org->locale, 'sector' => $org->sector, 'address' => (array) $org->billing_address,
                ];
            })
            ->schema(self::detailFields())
            ->action(fn (array $data, Model $record) => self::perform(fn (PlatformAdmin $admin) => app(ClientManager::class)->updateDetails($admin, self::organization($record), $data), __('clients.edit.done')));
    }

    /**
     * @return list<Component|Field>
     */
    public static function detailFields(): array
    {
        return [
            TextInput::make('name')->label(__('clients.info.name'))->required()->maxLength(255),
            TextInput::make('billing_name')->label(__('clients.info.legal_name'))->maxLength(255)->helperText(__('clients.info.legal_name_help')),
            TextInput::make('billing_vat_number')->label(__('clients.info.vat'))->maxLength(40),
            TextInput::make('address.street')->label(__('clients.info.street'))->maxLength(255),
            TextInput::make('address.number')->label(__('clients.info.number'))->maxLength(20),
            TextInput::make('address.postal_code')->label(__('clients.info.postal_code'))->maxLength(20),
            TextInput::make('address.city')->label(__('clients.info.city'))->maxLength(120),
            TextInput::make('address.country')->label(__('clients.info.address_country'))->maxLength(2)->helperText(__('clients.info.country_help')),
            TextInput::make('country')->label(__('clients.info.country'))->required()->length(2)->helperText(__('clients.info.country_help')),
            Select::make('timezone')->label(__('clients.info.timezone'))->required()->native(false)->searchable()->options(fn (): array => array_combine(timezone_identifiers_list(), timezone_identifiers_list())),
            Select::make('locale')->label(__('clients.info.locale'))->required()->native(false)->options(fn (): array => collect((array) config('rendivoo.locales'))->mapWithKeys(fn (string $locale): array => [$locale => strtoupper($locale)])->all()),
            TextInput::make('sector')->label(__('clients.info.sector'))->maxLength(60),
        ];
    }

    public static function extendTrial(): Action
    {
        return Action::make('extend_trial')->label(__('clients.actions.extend_trial'))->icon('heroicon-m-clock')->color('gray')
            ->visible(fn (Model $record): bool => ClientManager::canExtendTrial(self::organization($record)) && AdminAccess::can('subscriptions.edit'))
            ->modalHeading(__('clients.trial.heading'))->modalDescription(__('clients.trial.description'))
            ->schema(self::trialFields())
            ->action(fn (array $data, Model $record) => self::perform(fn (PlatformAdmin $admin) => app(ClientManager::class)->extendTrial($admin, self::organization($record), (int) $data['days'], self::reason($data)), __('clients.trial.done')));
    }

    /** Accorde une formule sans paiement (essais et démonstrations : aucun prestataire de paiement n'est encore branché). */
    public static function grantPlan(): Action
    {
        return Action::make('grant_plan')->label(__('clients.actions.grant_plan'))->icon('heroicon-m-gift')->color('gray')
            ->visible(fn (Model $record): bool => ClientManager::canGrantPlan(self::organization($record)) && AdminAccess::can('subscriptions.edit'))
            ->modalHeading(__('clients.grant.heading'))->modalDescription(__('clients.grant.description'))
            ->schema([
                Select::make('plan_id')->label(__('clients.grant.plan'))->required()->native(false)
                    ->options(fn (): array => PlatformPlan::query()->where('status', Plan::ACTIVE)->whereNull('superseded_by')->whereIn('plan_type', Plan::BILLABLE_TYPES)->orderBy('price_monthly_cents')->pluck('name', 'id')->all()),
                Textarea::make('reason')->label(__('admin.sensitive.reason'))->helperText(__('admin.sensitive.reason_help'))->rows(3)->maxLength(500),
            ])
            ->action(fn (array $data, Model $record) => self::perform(fn (PlatformAdmin $admin) => app(ClientManager::class)->grantPlan($admin, self::organization($record), (string) $data['plan_id'], self::reason($data)), __('clients.grant.done')));
    }

    /**
     * @return list<TextInput|Textarea>
     */
    public static function trialFields(): array
    {
        return [
            TextInput::make('days')->label(__('clients.trial.days'))->numeric()->integer()->required()->default(14)->minValue(1)->maxValue(ClientManager::MAX_EXTENSION_DAYS)->helperText(__('clients.trial.days_help', ['max' => ClientManager::MAX_EXTENSION_DAYS])),
            Textarea::make('reason')->label(__('admin.sensitive.reason'))->helperText(__('admin.sensitive.reason_help'))->rows(3)->maxLength(500),
        ];
    }

    public static function tag(): Action
    {
        return Action::make('tag')->visible(fn (): bool => AdminAccess::can('clients.edit'))->label(__('clients.actions.tag_add'))->icon('heroicon-m-tag')->color('gray')->modalHeading(__('clients.actions.tag_add'))
            ->schema([TextInput::make('tag')->label(__('clients.tag'))->required()->maxLength(30)->helperText(__('clients.tag_help'))])
            ->action(fn (array $data, Model $record) => self::perform(fn (PlatformAdmin $admin) => app(ClientManager::class)->addTag($admin, self::organization($record), (string) $data['tag']), __('clients.saved')));
    }

    /**
     * Catégories de données à exporter, avec l'avertissement sur les données personnelles.
     *
     * @return list<CheckboxList>
     */
    public static function exportFields(): array
    {
        return [
            CheckboxList::make('groups')->label(__('clients.export.groups'))->required()->default(['identity', 'subscription', 'usage'])->columns(['default' => 1, 'md' => 2])
                ->options(collect(ClientExport::GROUPS)->mapWithKeys(fn (string $group): array => [$group => __("clients.export.group.{$group}")])->all())
                ->descriptions(collect(ClientExport::GROUPS)->mapWithKeys(fn (string $group): array => [$group => __("clients.export.group_help.{$group}")])->all())
                ->helperText(__('clients.export.privacy')),
        ];
    }

    /**
     * Télécharge l'export ; la consultation de données client est consignée par `ClientExport`.
     *
     * @param  Builder<PlatformOrganization>  $query
     * @param  array<array-key, mixed>  $groups
     * @param  array<string, mixed>  $filters
     */
    public static function download(Builder $query, array $groups, array $filters, string $scope): StreamedResponse
    {
        return ClientExport::download(self::admin(), $query, array_map('strval', array_values($groups)), $filters, $scope);
    }
}
