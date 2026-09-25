<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateOwner extends Command
{
    protected $signature = 'hub:create-owner';

    protected $description = 'Create an owner account using an interactive password prompt';

    public function handle(): int
    {
        $data = ['name' => $this->ask('Name'), 'email' => $this->ask('Email'), 'password' => $this->secret('Password (at least 12 characters)')];
        $v = Validator::make($data, ['name' => 'required|string|max:150', 'email' => 'required|email|max:255|unique:users', 'password' => 'required|string|min:12']);
        if ($v->fails()) {
            foreach ($v->errors()->all() as $error) {
                $this->error($error);
            }

return self::FAILURE;
        }
        User::create($data);
        $this->info('Owner created. Sign in to Content Hub.');

        return self::SUCCESS;
    }
}
