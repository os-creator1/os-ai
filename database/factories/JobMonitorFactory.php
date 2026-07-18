<?php

    namespace Database\Factories;

    use App\Models\Job;
    use App\Models\JobMonitor;
    use Illuminate\Database\Eloquent\Factories\Factory;
    use Illuminate\Support\Carbon;

    class JobMonitorFactory extends Factory
    {
        protected $model = JobMonitor::class;

        public function definition(): array
        {
            return [
                'uid'          => $this->faker->word(),
                'subject_name' => $this->faker->name(),
                'subject_id'   => $this->faker->randomNumber(),
                'batch_id'     => $this->faker->word(),
                'job_type'     => $this->faker->word(),
                'error'        => $this->faker->word(),
                'data'         => $this->faker->word(),
                'status'       => $this->faker->word(),
                'created_at'   => Carbon::now(),
                'updated_at'   => Carbon::now(),

                'job_id' => Job::factory(),
            ];
        }

    }
