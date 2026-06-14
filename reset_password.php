<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';

use App\Models\User;
use Illuminate\Support\Facades\Hash;

$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$user = User::where('email', 'azerty@gmail.com')->first();
if ($user) {
    echo "User found: {$user->nom}\n";
    $user->password = Hash::make('azerty123');
    $user->save();
    echo "Password reset to: azerty123\n";
} else {
    echo "User not found\n";
}
