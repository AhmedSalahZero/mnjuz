<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        // Get all seeder classes from the database/seeders directory
        $seederFiles = $this->getSeederFiles();

        foreach ($seederFiles as $seederClass) {
            // Only run if the seeder hasn't been run before
            if (!$this->hasSeederRun($seederClass)) {
                $this->callSeeder($seederClass);
                $this->markSeederAsExecuted($seederClass);
            }
        }
    }

    /**
     * Get all seeder class names from the database/seeders directory.
     *
     * @return array
     */
    protected function getSeederFiles()
    {
        $files = File::files(database_path('seeders'));
        $seederClasses = [];

        foreach ($files as $file) {
            // Skip DatabaseSeeder.php
            if ($file->getFilename() === 'DatabaseSeeder.php') {
                continue;
            }

            $className = $this->getSeederClassName($file);
            if ($this->isSeederClass($className)) {
                $seederClasses[] = $className;
            }
        }

        return $seederClasses;
    }

    /**
     * Get the full class name from the file path.
     *
     * @param  \SplFileInfo  $file
     * @return string
     */
    protected function getSeederClassName($file)
    {
        $fileName = $file->getFilenameWithoutExtension();
        return 'Database\\Seeders\\' . $fileName;
    }

    /**
     * Check if the file corresponds to a valid seeder class.
     *
     * @param  string  $className
     * @return bool
     */
    protected function isSeederClass($className)
    {
        return class_exists($className) && is_subclass_of($className, \Illuminate\Database\Seeder::class);
    }

    /**
     * Run the seeder class.
     *
     * @param  string  $seederClass
     * @return void
     */
    protected function callSeeder($seederClass)
    {
        $this->command->info("Running seeder: $seederClass");
        $this->call($seederClass);
    }

    /**
     * Check if the seeder has already been run.
     *
     * @param  string  $seederClass
     * @return bool
     */
    protected function hasSeederRun($seederClass)
    {
        return DB::table('seeder_histories')->where('seeder_name', $seederClass)->exists();
    }

    /**
     * Mark the seeder as executed in the database.
     *
     * @param  string  $seederClass
     * @return void
     */
    protected function markSeederAsExecuted($seederClass)
    {
        DB::table('seeder_histories')->insert([
            'seeder_name' => $seederClass,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}