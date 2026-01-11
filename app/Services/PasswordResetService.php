<?php

// app/Services/PasswordResetService.php

namespace App\Services;

use App\Helpers\Email;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use App\Models\PasswordResetToken;
use App\Models\User;

class PasswordResetService
{
    public function generateResetLink(string $email): string
    {
        $token = Str::random(60);
        $resetLink = URL::route('password.reset', ['token' => $token, 'email' => $email]);

        $this->storeTokenInDatabase($email, $token);
        $this->sendResetEmail($email, $resetLink);

        return $resetLink;
    }

    public function sendResetEmail(string $email, string $resetLink): void
    {
        $user = User::where('email', $email)->first();
        Email::sendPasswordReset('Reset Password', $user, $resetLink);
    }

    public function resetPassword($request)
    {
        $email = $request->input('email');
        $token = $request->input('token');

        if($this->verifyResetCode($email, $token)){
            $user = User::where('email', $email)
                ->update([
                    'password' => Hash::make($request->input('password'))
                ]);

            $this->clearResetCode($email);

            $updatedUser = User::where('email', $email)->first();
            Email::send('Password Reset Notification', $updatedUser);

            return $user;
        }
    }

    public function verifyResetCode(string $email, string $token): bool
    {
        $storedToken = $this->getStoredCode($email);

        return isset($storedToken) && Hash::check($token, $storedToken);
    }

    private function getStoredCode(string $email): ?string
    {
        $passwordReset = PasswordResetToken::where('email', $email)->first();

        return $passwordReset ? $passwordReset->token : null;
    }

    private function storeTokenInDatabase(string $email, string $token): void
    {
        PasswordResetToken::updateOrInsert(
            ['email' => $email],
            ['token' => Hash::make($token), 'created_at' => now()]
        );
    }

    private function clearResetCode(string $email): void
    {
        PasswordResetToken::where('email', $email)->delete();
    }
}
