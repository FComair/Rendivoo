<?php

declare(strict_types=1);

use App\Modules\Admin\Filament\Pages\ClientReports;
use App\Modules\Admin\Filament\Resources\OrganizationResource\Pages\ListOrganizations;
use App\Modules\Admin\Filament\Resources\OrganizationResource\Pages\ViewOrganization;
use App\Modules\Admin\Models\PlatformAdmin;
use App\Modules\Admin\Models\PlatformOrganization;
use App\Modules\Admin\Notifications\AccountStatusNotification;
use App\Modules\Admin\Services\ClientDirectory;
use App\Modules\Admin\Services\ClientExport;
use App\Modules\Admin\Services\ClientManager;
use App\Modules\Admin\Services\ClientProfile;
use App\Modules\Admin\Services\ClientReport;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\AuthScenarios;

uses(AuthScenarios::class);

/**
 * Super Admin > Clients (docs/31) : liste et indicateurs, états d'abonnement dérivés, fiche, prolongation d'essai, étiquettes, notes,
 * actions groupées, export, rapports, accès réservé.
 */
beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Notification::fake();

    $this->admin = PlatformAdmin::query()->create(['email' => 'fondateur@rendivoo.test', 'password' => 'mot-de-passe-du-fondateur-2026!']);
    ['user' => $this->owner, 'org' => $this->org] = $this->activatedAccount(['name' => 'Salon Aurore', 'status' => 'trial', 'trial_ends_at' => now()->addDays(5)]);

    $this->set = fn (string $orgId, array $values) => DB::connection('pgsql_owner')->table('organizations')->where('id', $orgId)->update($values);
    $this->row = fn (?string $id = null): PlatformOrganization => ClientDirectory::query()->where('organizations.id', $id ?? $this->org->id)->firstOrFail();
    $this->manager = fn (): ClientManager => app(ClientManager::class);
    $this->org2 = fn (?PlatformOrganization $o = null) => PlatformOrganization::query()->findOrFail(($o ?? $this->org)->id);
    $this->audit = fn (string $action) => DB::connection('pgsql_platform')->table('audit_logs')->where('action', $action)->get();
});

describe('états d\'abonnement', function (): void {
    it('dérive l\'état d\'un compte en essai, expiré, en pause et sans abonnement', function (): void {
        expect(($this->row)()->subscription_state)->toBe('trial');

        ($this->set)($this->org->id, ['status' => 'suspended', 'suspension_reason' => 'system.trial_ended']);
        expect(($this->row)()->subscription_state)->toBe('expired');

        ($this->set)($this->org->id, ['status' => 'paused', 'suspension_reason' => null]);
        expect(($this->row)()->subscription_state)->toBe('paused');

        ($this->set)($this->org->id, ['status' => 'suspended', 'suspension_reason' => 'Fraude']);
        expect(($this->row)()->subscription_state)->toBe('none');
    });

    it('compte les clients par état dans les indicateurs', function (): void {
        $metrics = ClientDirectory::metrics();

        expect($metrics)->toHaveKeys(['total', 'trial', 'active', 'past_due', 'cancelled', 'expired', 'mrr_cents'])
            ->and($metrics['total'])->toBe(1)->and($metrics['trial'])->toBe(1)->and($metrics['mrr_cents'])->toBe(0);
    });
});

describe('liste', function (): void {
    it('affiche les clients et se laisse chercher par nom et par e-mail du propriétaire', function (): void {
        $this->activatedAccount(['name' => 'Cabinet Zéphyr'], 'zephyr@example.com');

        Livewire::actingAs($this->admin, 'platform')->test(ListOrganizations::class)
            ->assertSuccessful()
            ->assertSee('Salon Aurore')->assertSee('Cabinet Zéphyr')
            ->searchTable('zephyr@example.com')
            ->assertSee('Cabinet Zéphyr')->assertDontSee('Salon Aurore')
            ->searchTable('Aurore')
            ->assertSee('Salon Aurore')->assertDontSee('Cabinet Zéphyr');
    });

    it('filtre par état d\'abonnement', function (): void {
        ['org' => $other] = $this->activatedAccount(['name' => 'Cabinet Zéphyr', 'status' => 'trial'], 'zephyr@example.com');
        ($this->set)($other->id, ['status' => 'suspended', 'suspension_reason' => 'system.trial_ended']);

        Livewire::actingAs($this->admin, 'platform')->test(ListOrganizations::class)
            ->filterTable('subscription', ['expired'])
            ->assertSee('Cabinet Zéphyr')->assertDontSee('Salon Aurore');
    });
});

describe('fiche client', function (): void {
    it('s\'ouvre et trace la consultation', function (): void {
        Livewire::actingAs($this->admin, 'platform')->test(ViewOrganization::class, ['record' => $this->org->id])->assertSuccessful()->assertSee('Salon Aurore');

        expect(($this->audit)('client.viewed'))->toHaveCount(1);
    });

    it('signale un essai proche de sa fin', function (): void {
        ($this->set)($this->org->id, ['trial_ends_at' => now()->addDays(2)]);
        $keys = collect(app(ClientProfile::class)->health(($this->row)()))->pluck('key')->all();

        expect($keys)->toContain('trial_ending');
    });
});

describe('prolongation d\'essai', function (): void {
    it('ajoute les jours à partir de la fin d\'essai, trace et prévient le propriétaire', function (): void {
        $before = ($this->org2)()->trial_ends_at;
        $end = ($this->manager)()->extendTrial($this->admin, ($this->org2)(), 10, 'Demande du client');

        expect($end->isSameDay($before->copy()->addDays(10)))->toBeTrue()
            ->and(($this->org2)()->status)->toBe('trial');
        expect(($this->audit)('client.trial_extended'))->toHaveCount(1);
        Notification::assertSentOnDemand(AccountStatusNotification::class);
    });

    it('réactive un compte suspendu à la fin de son essai', function (): void {
        ($this->set)($this->org->id, ['status' => 'suspended', 'suspension_reason' => 'system.trial_ended', 'suspended_at' => now(), 'trial_ends_at' => now()->subDay()]);

        ($this->manager)()->extendTrial($this->admin, ($this->org2)(), 7, null);

        $org = ($this->org2)();
        expect($org->status)->toBe('trial')->and($org->suspension_reason)->toBeNull()->and($org->trial_ends_at->isFuture())->toBeTrue();
    });

    it('refuse une durée hors limites et un compte actif', function (): void {
        expect(fn () => ($this->manager)()->extendTrial($this->admin, ($this->org2)(), 0, null))->toThrow(InvalidArgumentException::class)
            ->and(fn () => ($this->manager)()->extendTrial($this->admin, ($this->org2)(), ClientManager::MAX_EXTENSION_DAYS + 1, null))->toThrow(InvalidArgumentException::class);

        ($this->set)($this->org->id, ['status' => 'active']);
        expect(fn () => ($this->manager)()->extendTrial($this->admin, ($this->org2)(), 5, null))->toThrow(InvalidArgumentException::class);
    });
});

describe('formule accordée sans paiement', function (): void {
    beforeEach(function (): void {
        $this->planId = (string) DB::connection('pgsql_owner')->table('plans')->where('code', 'business')->whereNull('superseded_by')->value('id');
    });

    it('active le compte avec la formule, sans abonnement ni facture, et trace', function (): void {
        ($this->manager)()->grantPlan($this->admin, ($this->org2)(), $this->planId, 'Essai interne');

        $org = ($this->org2)();
        expect($org->status)->toBe('active')->and($org->plan_id)->toBe($this->planId);
        expect(DB::connection('pgsql_owner')->table('subscriptions')->where('org_id', $org->id)->count())->toBe(0)
            ->and(DB::connection('pgsql_owner')->table('invoices')->where('org_id', $org->id)->count())->toBe(0);
        expect(($this->audit)('client.plan_granted'))->toHaveCount(1);
    });

    it('rétablit un compte suspendu à la fin de son essai', function (): void {
        ($this->set)($this->org->id, ['status' => 'suspended', 'suspension_reason' => 'system.trial_ended', 'suspended_at' => now()]);

        ($this->manager)()->grantPlan($this->admin, ($this->org2)(), $this->planId, null);

        $org = ($this->org2)();
        expect($org->status)->toBe('active')->and($org->suspension_reason)->toBeNull();
    });

    it('refuse une formule inconnue, un compte suspendu par la plateforme et un abonnement en cours', function (): void {
        expect(fn () => ($this->manager)()->grantPlan($this->admin, ($this->org2)(), '00000000-0000-0000-0000-000000000000', null))->toThrow(InvalidArgumentException::class);

        ($this->set)($this->org->id, ['status' => 'suspended', 'suspension_reason' => 'Fraude']);
        expect(fn () => ($this->manager)()->grantPlan($this->admin, ($this->org2)(), $this->planId, null))->toThrow(InvalidArgumentException::class);

        ($this->set)($this->org->id, ['status' => 'active', 'suspension_reason' => null]);
        DB::connection('pgsql_owner')->table('subscriptions')->insert([
            'id' => (string) Illuminate\Support\Str::uuid7(), 'org_id' => $this->org->id, 'plan_id' => $this->planId, 'status' => 'active', 'provider' => 'fake',
            'current_period_start' => now(), 'current_period_end' => now()->addMonth(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        expect(fn () => ($this->manager)()->grantPlan($this->admin, ($this->org2)(), $this->planId, null))->toThrow(InvalidArgumentException::class);
    });
});

describe('informations, étiquettes et notes', function (): void {
    it('corrige les informations et journalise l\'ancien et le nouveau contenu', function (): void {
        $changes = ($this->manager)()->updateDetails($this->admin, ($this->org2)(), ['name' => 'Salon Aurore Bruxelles']);

        expect(($this->org2)()->name)->toBe('Salon Aurore Bruxelles')->and($changes['name'][0])->toBe('Salon Aurore');
        expect(($this->audit)('client.updated'))->toHaveCount(1);
    });

    it('refuse un nom vide', function (): void {
        expect(fn () => ($this->manager)()->updateDetails($this->admin, ($this->org2)(), ['name' => '  ']))->toThrow(InvalidArgumentException::class);
    });

    it('ajoute, déduplique et retire une étiquette', function (): void {
        ($this->manager)()->addTag($this->admin, ($this->org2)(), '  VIP Client ');
        ($this->manager)()->addTag($this->admin, ($this->org2)(), 'vip-client');

        expect(app(ClientProfile::class)->tags(($this->org2)()))->toBe(['vip-client']);
        expect(($this->audit)('client.tag_added'))->toHaveCount(1);

        ($this->manager)()->removeTag($this->admin, ($this->org2)(), 'vip-client');
        expect(app(ClientProfile::class)->tags(($this->org2)()))->toBe([]);
    });

    it('garde les notes internes sans exposer leur texte dans le journal et sans les rendre lisibles par le client', function (): void {
        ($this->manager)()->addNote($this->admin, ($this->org2)(), 'Appeler jeudi à propos de la facture.');

        expect(app(ClientProfile::class)->notes(($this->org2)()))->toHaveCount(1);
        expect(json_encode(($this->audit)('client.note_added')->first()->meta ?? ''))->not->toContain('facture');
        expect(fn () => DB::connection('pgsql')->table('client_notes')->count())->toThrow(QueryException::class);
    });
});

describe('actions groupées', function (): void {
    it('prolonge les essais éligibles et ignore les autres', function (): void {
        ['org' => $active] = $this->activatedAccount(['name' => 'Cabinet Actif', 'status' => 'active'], 'actif@example.com');

        $result = ($this->manager)()->bulk($this->admin, [$this->org->id, $active->id], 'extend_trial', fn (PlatformOrganization $org) => ($this->manager)()->extendTrial($this->admin, $org, 3, null));

        expect($result)->toBe(['done' => 1, 'skipped' => 1]);
        expect(($this->audit)('client.bulk'))->toHaveCount(1);
    });
});

describe('export', function (): void {
    it('n\'inclut les données de contact que si on les demande et trace l\'export', function (): void {
        $query = ClientDirectory::query();

        $response = ClientExport::download($this->admin, $query, ['identity'], [], 'filtered');
        ob_start();
        $response->sendContent();
        $csv = (string) ob_get_clean();

        expect($csv)->toContain('Salon Aurore')->not->toContain('proprio@example.com');
        expect(($this->audit)('client.exported'))->toHaveCount(1);

        $response = ClientExport::download($this->admin, $query, ['identity', 'contact'], [], 'filtered');
        ob_start();
        $response->sendContent();
        expect((string) ob_get_clean())->toContain('proprio@example.com');
    });

    it('neutralise les formules de tableur', function (): void {
        ($this->manager)()->updateDetails($this->admin, ($this->org2)(), ['name' => '=HYPERLINK("http://x")']);

        $response = ClientExport::download($this->admin, ClientDirectory::query(), ['identity'], [], 'filtered');
        ob_start();
        $response->sendContent();

        expect((string) ob_get_clean())->not->toContain(',=HYPERLINK')->not->toContain('"=HYPERLINK');
    });
});

describe('rapports', function (): void {
    it('calcule les indicateurs et affiche la page', function (): void {
        $metrics = app(ClientReport::class)->compute([]);

        expect($metrics['total'])->toBe(1)->and($metrics['trials_started'])->toBe(1)->and($metrics['by_state']['trial'])->toBe(1);

        Livewire::actingAs($this->admin, 'platform')->test(ClientReports::class)->assertSuccessful();
    });
});

describe('accès', function (): void {
    it('refuse les rapports et la liste aux rôles de support restreints', function (): void {
        $agent = PlatformAdmin::query()->create(['email' => 'agent@rendivoo.test', 'password' => 'mot-de-passe-de-lagent-2026!']);
        $agent->forceFill(['support_role' => PlatformAdmin::SUPPORT_AGENT])->save();

        $this->actingAs($agent, 'platform');
        expect(ClientReports::canAccess())->toBeFalse();
    });
});
