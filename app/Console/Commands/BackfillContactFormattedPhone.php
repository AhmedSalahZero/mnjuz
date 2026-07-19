<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Services\PhoneService;
use Illuminate\Console\Command;

class BackfillContactFormattedPhone extends Command
{
    protected $signature = 'contacts:backfill-formatted-phone
                            {--chunk=500 : Number of contacts per batch}
                            {--organization= : Limit to a single organization id}';

    protected $description = 'Fill contacts.formatted_phone for existing rows that have a phone number';

    public function handle(): int
    {
        $chunkSize = max(1, (int) $this->option('chunk'));
        $organizationId = $this->option('organization');

        $query = Contact::query()
            ->whereNull('formatted_phone')
            ->whereNotNull('phone')
            ->where('phone', '!=', '');

        if ($organizationId !== null && $organizationId !== '') {
            $query->where('organization_id', (int) $organizationId);
        }

        $total = (clone $query)->count();
        if ($total === 0) {
            $this->info('No contacts need backfill.');

            return self::SUCCESS;
        }

        $this->info("Backfilling formatted_phone for {$total} contact(s)...");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $updated = 0;

        $query->orderBy('id')->chunkById($chunkSize, function ($contacts) use (&$updated, $bar) {
            foreach ($contacts as $contact) {
                $contact->formatted_phone = PhoneService::formatForDisplay($contact->phone);
                $contact->saveQuietly();
                $updated++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("Updated {$updated} contact(s).");

        return self::SUCCESS;
    }
}
