<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * Les dates sont écrites en UTC sans décalage : la session PostgreSQL doit donc rester en UTC, sinon un serveur réglé sur un autre fuseau
 * relit « 09:00 UTC » comme « 09:00 heure locale » et décale chaque rendez-vous (pris à 10h00 belges, affiché à 09h00).
 */
it('garde chaque connexion PostgreSQL en UTC', function (string $connection): void {
    expect(DB::connection($connection)->selectOne('show timezone')->TimeZone)->toBe('UTC');
})->with(['pgsql', 'pgsql_owner', 'pgsql_platform']);

it('relit à l\'identique une heure UTC écrite sans décalage', function (): void {
    $db = DB::connection('pgsql_owner');
    $db->statement('create temporary table tz_probe (at timestamptz not null)');
    $db->insert('insert into tz_probe (at) values (?)', ['2026-11-04 09:00:00']);

    expect($db->selectOne("select to_char(at at time zone 'Europe/Brussels', 'HH24:MI') as local from tz_probe")->local)->toBe('10:00');
});
