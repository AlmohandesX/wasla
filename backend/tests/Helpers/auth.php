<?php

use App\Models\User;

const PW = 'correct-horse-9';

function register(array $overrides = [])
{
    return test()->postJson('/api/v1/auth/register', array_merge([
        'name' => 'Ayse Yilmaz',
        'email' => 'ayse@example.com',
        'password' => PW,
        'locale' => 'tr',
        'privacy_consent' => true,
    ], $overrides));
}

function login(string $email, string $password = PW, string $ip = '10.0.0.1')
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password]);
}

function owner(array $attrs = []): User
{
    return User::factory()->create(array_merge(['password' => PW], $attrs));
}

function bearer(User $user): array
{
    return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
}
