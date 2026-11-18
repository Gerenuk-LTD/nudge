<?php

use App\Models\User;

test('the home page sends guests to sign in', function () {
    $this->get(route('home'))->assertRedirect('/login');
});

test('the home page sends signed-in users on to the app', function () {
    $this->actingAs(User::factory()->create());

    $this->followingRedirects()->get(route('home'))->assertOk();
    $this->get('/login')->assertRedirect('/dashboard');
});
