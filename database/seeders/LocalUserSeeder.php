<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Cria o usuário local (T201.3).
 *
 * ⚠️ A senha NÃO tem valor padrão no código. Um default como "password" acaba
 * em produção — sempre acaba. Sem `PICOGLI_USER_PASSWORD` no ambiente, o seeder
 * gera uma senha aleatória e a imprime UMA vez.
 */
class LocalUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('PICOGLI_USER_EMAIL', 'eu@picogli.local');
        $password = env('PICOGLI_USER_PASSWORD');
        $generated = false;

        if ($password === null || $password === '') {
            $password = Str::password(16);
            $generated = true;
        }

        $user = User::updateOrCreate(
            ['email' => $email],
            ['name' => env('PICOGLI_USER_NAME', 'PicoGli'), 'password' => Hash::make($password)],
        );

        $this->command->info("Usuário: {$user->email}");

        if ($generated) {
            $this->command->warn("Senha gerada (anote, não será mostrada de novo): {$password}");
        }
    }
}
