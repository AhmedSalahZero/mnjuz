<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\EmailTemplate;

class EmailTemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $templates = [
            [
                'name' => 'Reset Password',
                'subject' => 'Reset Password',
                'body' => '<p>Hi {{FirstName}},</p><p>You have submitted a password reset for your account. If this was not you, simply ignore this email. But if you did, click on this link {{Link}} to reset your password. If that doesn\'t work, copy and paste this link to your browser.</p><p>{{Link}}</p>',
                'updated_by' => 1,
                'updated_at' => now()
            ],
            [
                'name' => 'Password Reset Notification',
                'subject' => 'Your Password has been reset',
                'body' => '<p>Hi {{FirstName}},</p><p>Your password has been reset successfully! You can login to your account.</p>',
                'updated_by' => 1,
                'updated_at' => now()
            ],
            [
                'name' => 'Registration',
                'subject' => 'Welcome to {{CompanyName}}',
                'body' => '<p>Hello {{FirstName}},</p><p>I am Joe, the founder of {{CompanyName}} and I would like to extend my heartfelt welcome to you for joining our platform. We are excited to have you onboard. Feel free to explore our platform and let us know if you have any questions or need assistance. </p><p>Thank you for choosing our platform!</p><p>Best regards,</p><p>The {{CompanyName}} Team</p><p><br></p>',
                'updated_by' => 1,
                'updated_at' => now()
            ],
            [
                'name' => 'Invite',
                'subject' => 'You have been invited to join {{CompanyName}}',
                'body' => '<p>Hello there,</p><p><span style="color: rgb(55, 65, 81);">You\'ve received an invitation to join {{CompanyName}}\'s account from {{FirstName}}.</span></p><p>To get started, simply click on the following link: {{Link}}</p><p>Thank you and welcome aboard!</p><p>Best regards,</p><p>{{CompanyName}} Team </p><p><br></p>',
                'updated_by' => 1,
                'updated_at' => now()
            ],
            [
                'name' => 'Verify Email',
                'subject' => 'Please verify your email',
                'body' => '<p>Hi {firstName},</p><p>Please verify your email by clicking on the link below.</p><p>{verificationLink}</p><p><span style="letter-spacing: 0em; text-align: var(--bs-body-text-align);">Best regards,</span></p><p><br></p>',
                'updated_by' => 1,
                'updated_at' => now()
            ],
            [
                'name' => 'Payment Success',
                'subject' => 'Your subscription payment was successful',
                'body' => '<p>Hello,</p><p>Your subscription payment was successful!</p>',
                'updated_by' => 1,
                'updated_at' => now()
            ],
            [
                'name' => 'Payment Failed',
                'subject' => 'Your subscription payment was unsuccessful',
                'body' => '<p>Hello,</p><p>Your payment was unsuccessful, please check your payment and confirm.</p><p><br></p>',
                'updated_by' => 1,
                'updated_at' => now()
            ],
            [
                'name' => 'Subscription Renewal',
                'subject' => 'Your subscription has been renewed successfully',
                'body' => '<p>Hi {{FirstName}},</p><p>Your subscription has been renewed successfully. </p>',
                'updated_by' => 1,
                'updated_at' => now()
            ],
            [
                'name' => 'Subscription Plan Purchase',
                'subject' => 'Your have subscribed to {{plan}} successfully',
                'body' => '<p>Hi {{FirstName}},</p><p>You have been subscribed to the {{plan}} plan successfully.</p>',
                'updated_by' => 1,
                'updated_at' => now()
            ],
        ];

        foreach ($templates as $template) {
            EmailTemplate::firstOrCreate(
                ['name' => $template['name']],
                $template
            );
        }
    }
}
