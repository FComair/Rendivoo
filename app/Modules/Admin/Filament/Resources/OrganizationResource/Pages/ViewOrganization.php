<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\OrganizationResource\Pages;

use App\Modules\Admin\Filament\Resources\OrganizationResource;
use App\Modules\Admin\Filament\Support\ClientActions;
use App\Modules\Admin\Filament\Support\ResolvesRecordByKey;
use App\Modules\Admin\Filament\Support\SensitiveActionFields;
use App\Modules\Admin\Models\PlatformAdmin;
use App\Modules\Admin\Models\PlatformOrganization;
use App\Modules\Admin\Services\AccessRecovery;
use App\Modules\Admin\Services\ClientDirectory;
use App\Modules\Admin\Services\ClientManager;
use App\Modules\Admin\Services\OrganizationLifecycle;
use App\Modules\Admin\Support\AdminAccess;
use App\Modules\Tenancy\Models\Organization;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use InvalidArgumentException;

/**
 * Fiche d'un client (docs/31) : vue d'ensemble, informations et équipe, abonnement, usage et santé, notes, puis factures, activité et
 * support ; actions : modifier, prolonger l'essai, noter, étiqueter, exporter la fiche, et les actions de cycle de vie (suspendre, lever,
 * supprimer : motif facultatif, docs/02 §3.3). L'ouverture de la fiche est consignée (consultation de données client).
 */
class ViewOrganization extends ViewRecord
{
    use ResolvesRecordByKey;

    protected static string $resource = OrganizationResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        app(ClientManager::class)->viewed(ClientActions::admin(), $this->organization());
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            ClientActions::editDetails()->record(fn () => $this->organization()),
            ClientActions::extendTrial()->record(fn () => $this->organization()),
            ClientActions::grantPlan()->record(fn () => $this->organization()),
            $this->noteAction(),
            ClientActions::tag()->record(fn () => $this->organization()),
            $this->exportProfileAction(),
            $this->lifecycleAction('suspend', 'danger', [Organization::STATUS_TRIAL, Organization::STATUS_ACTIVE, Organization::STATUS_PAST_DUE, Organization::STATUS_PAUSED], 'suspend'),
            $this->lifecycleAction('lift', 'success', [Organization::STATUS_SUSPENDED], 'lift'),
            $this->lifecycleAction('schedule_deletion', 'danger', [Organization::STATUS_TRIAL, Organization::STATUS_ACTIVE, Organization::STATUS_PAST_DUE, Organization::STATUS_SUSPENDED, Organization::STATUS_PAUSED], 'scheduleDeletion'),
            $this->lifecycleAction('delete_now', 'danger', [Organization::STATUS_TRIAL, Organization::STATUS_ACTIVE, Organization::STATUS_PAST_DUE, Organization::STATUS_SUSPENDED, Organization::STATUS_PAUSED, Organization::STATUS_PENDING_DELETION], 'deleteNow', true),
            $this->lifecycleAction('cancel_deletion', 'success', [Organization::STATUS_PENDING_DELETION], 'cancelDeletion'),
            $this->unblockAccessAction(),
        ];
    }

    private function noteAction(): Action
    {
        return Action::make('add_note')->visible(fn (): bool => AdminAccess::can('clients.edit'))->label(__('clients.actions.add_note'))->icon('heroicon-m-pencil-square')->color('gray')->modalHeading(__('clients.actions.add_note'))
            ->schema([Textarea::make('body')->label(__('clients.notes.body'))->required()->rows(5)->maxLength(5000)->helperText(__('clients.notes.help'))])
            ->action(fn (array $data) => ClientActions::perform(fn (PlatformAdmin $admin) => app(ClientManager::class)->addNote($admin, $this->organization(), (string) $data['body']), __('clients.notes.saved')));
    }

    /** Export CSV de la fiche d'un client (mêmes catégories, même avertissement, consigné). */
    private function exportProfileAction(): Action
    {
        return Action::make('export_profile')->visible(fn (): bool => AdminAccess::can('clients.export'))->label(__('clients.actions.export_profile'))->icon('heroicon-m-arrow-down-tray')->color('gray')
            ->modalHeading(__('clients.export.heading'))->modalDescription(__('clients.export.description', ['count' => 1]))->modalSubmitActionLabel(__('clients.export.confirm'))
            ->schema(ClientActions::exportFields())
            ->action(fn (array $data) => ClientActions::download(ClientDirectory::query()->where('organizations.id', $this->organization()->id), (array) ($data['groups'] ?? []), [], 'profile'));
    }

    /**
     * « Déblocage d'accès » : réinitialise la double authentification d'un membre qui a perdu son appareil ET ses codes de secours.
     * L'identité de la personne doit avoir été vérifiée par le support avant (case à cocher obligatoire, tracée dans l'audit).
     */
    private function unblockAccessAction(): Action
    {
        return Action::make('unblock_access')
            ->label(__('admin.access.label'))
            ->color('warning')
            ->modalHeading(__('admin.access.heading'))
            ->modalDescription(__('admin.access.description'))
            ->visible(fn (): bool => ! in_array($this->organization()->status, [Organization::STATUS_DELETED, Organization::STATUS_PENDING_DELETION], true) && AdminAccess::can('clients.edit'))
            ->schema(fn (): array => [
                Select::make('user_id')
                    ->label(__('admin.access.member'))
                    ->options(fn (): array => collect(app(AccessRecovery::class)->members($this->organization()))
                        ->mapWithKeys(fn (object $m): array => [$m->user_id => "{$m->email} ({$m->role})"])->all())
                    ->required(),
                Checkbox::make('identity_verified')->label(__('admin.access.identity_verified'))->accepted()->required(),
                Toggle::make('reset_password')->label(__('admin.access.reset_password'))->helperText(__('admin.access.reset_password_help')),
                ...SensitiveActionFields::make(),
            ])
            ->action(function (array $data, Action $action): void {
                [$adminId, $reason] = SensitiveActionFields::authorize($action, $data);

                try {
                    app(AccessRecovery::class)->unblock($this->organization(), (string) $data['user_id'], (bool) ($data['reset_password'] ?? false), $adminId, $reason);
                } catch (InvalidArgumentException) {
                    Notification::make()->danger()->title(__('admin.access.unknown_member'))->send();
                    $action->halt();
                }

                Notification::make()->success()->title(__('admin.access.done'))->send();
            });
    }

    /**
     * @param  list<string>  $statuses  statuts depuis lesquels l'action est proposée
     * @param  'suspend'|'lift'|'scheduleDeletion'|'deleteNow'|'cancelDeletion'  $method
     */
    private function lifecycleAction(string $name, string $color, array $statuses, string $method, bool $irreversible = false): Action
    {
        return Action::make($name)
            ->label(__("admin.lifecycle.{$name}.label"))
            ->color($color)
            ->modalHeading(__("admin.lifecycle.{$name}.heading"))
            ->modalDescription(__("admin.lifecycle.{$name}.description"))
            // Une fois l'effacement commencé, la suppression ne peut plus être annulée ni accélérée.
            ->visible(fn (): bool => AdminAccess::can(in_array($name, ['suspend', 'lift'], true) ? 'clients.suspend' : 'clients.delete') && in_array($this->organization()->status, $statuses, true)
                && (! in_array($name, ['cancel_deletion', 'delete_now'], true) || $this->organization()->deletion_started_at === null))
            ->schema(SensitiveActionFields::make($irreversible))
            ->action(function (array $data, Action $action) use ($method, $name): void {
                $org = $this->organization();
                [$adminId, $reason] = SensitiveActionFields::authorize($action, $data);

                app(OrganizationLifecycle::class)->{$method}($org, $adminId, $reason);

                $org->refresh();
                Notification::make()->success()->title(__("admin.lifecycle.{$name}.done"))->send();
            });
    }

    private function organization(): PlatformOrganization
    {
        $record = $this->getRecord();
        assert($record instanceof PlatformOrganization);

        return $record;
    }
}
