<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Chaque jour : les échéances non payées dont la date est passée deviennent "en_retard".
// Nécessite sur le serveur une tâche cron : * * * * * php artisan schedule:run
Schedule::command('remboursements:retards')->dailyAt('00:30');

// Le 10 de chaque mois : rappel aux membres dont la cotisation du mois précédent n'est pas
// enregistrée (la retenue sur salaire est importée après la fin du mois).
Schedule::command('cotisations:rappels')->monthlyOn(10, '08:00');
