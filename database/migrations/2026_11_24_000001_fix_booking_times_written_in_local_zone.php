<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Corrige les heures des rendez-vous enregistrées avec un décalage (un rendez-vous pris à 10h00 belges apparaissait à 09h00).
 *
 * Cause : la session PostgreSQL n'était pas réglée sur UTC (voir `timezone` dans config/database.php). Laravel envoie les dates en
 * heure UTC SANS décalage (« 2026-11-04 09:00:00 ») ; un serveur réglé sur Europe/Brussels les lisait comme des heures belges, donc une
 * heure trop tôt en hiver et deux en été. L'instant relu, vu en heure belge, était l'heure UTC voulue.
 *
 * Correction : pour chaque horodatage de `bookings` écrit par l'application, `(valeur AT TIME ZONE <fuseau du serveur>) AT TIME ZONE 'UTC'`
 * redonne l'instant voulu. Le fuseau est celui par défaut du serveur (`reset_val`, indépendant de la session UTC de cette connexion) ;
 * si ce défaut est déjà UTC (développement, CI), rien n'était décalé et la migration ne fait rien.
 *
 * Périmètre volontairement limité aux rendez-vous (ce que le planning affiche). Les journaux en ajout seul ne sont pas modifiés. La contrainte
 * anti double réservation est retirée le temps de la mise à jour (un décalage ligne par ligne pourrait la heurter) puis remise à l'identique :
 * le tout est une seule transaction, elle est annulée entièrement si la contrainte ne se rétablit pas.
 */
return new class extends Migration
{
    private const COLUMNS = ['starts_at', 'ends_at', 'blocked_from', 'blocked_until', 'expires_at', 'cancelled_at', 'reminder_sent_at', 'created_at', 'updated_at'];

    public function up(): void
    {
        $zone = (string) (DB::selectOne("select reset_val from pg_settings where name = 'TimeZone'")->reset_val ?? 'UTC');

        if (in_array(strtoupper($zone), ['UTC', 'ETC/UTC', 'ETC/UCT', 'UCT', 'ZULU', 'ETC/ZULU', 'GMT', 'ETC/GMT'], true)) {
            return;
        }

        $assignments = implode(', ', array_map(static fn (string $column): string => "{$column} = ({$column} AT TIME ZONE ?) AT TIME ZONE 'UTC'", self::COLUMNS));

        DB::statement('ALTER TABLE bookings DROP CONSTRAINT bookings_no_double_booking');
        DB::update("UPDATE bookings SET {$assignments}", array_fill(0, count(self::COLUMNS), $zone));
        DB::statement(<<<'SQL'
            ALTER TABLE bookings ADD CONSTRAINT bookings_no_double_booking
            EXCLUDE USING gist (practitioner_id WITH =, tstzrange(blocked_from, blocked_until) WITH &&)
            WHERE (status IN ('pending', 'confirmed'))
        SQL);
    }

    /** Irréversible : l'ancien décalage était une erreur. */
    public function down(): void {}
};
