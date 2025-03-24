<?php

namespace App\Traits;

use App\Enums\InputMode;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

trait PSOPayloads
{

    public function initialize_payload($data)
    {
        $payload =
            [
                'base_url' => $data['base_url'],
                'datetime' => Carbon::parse($data['datetime'])->toAtomString() ?: Carbon::now()->toAtomString(),
                'description' => $data['description'],
                'organisation_id' => '2',
                'dataset_id' => $data['dataset_id'],
                'send_to_pso' => $data['send_to_pso'],

            ];

        if ($data['input_mode'] === InputMode::LOAD) {

            $payload = Arr::add($payload, 'dse_duration', $data['dse_duration']);
            $payload = Arr::add($payload, 'keep_pso_data', $data['keep_pso_data']);
            $payload = Arr::add($payload, 'process_type', $data['process_type']);
            $payload = Arr::add($payload, 'appointment_window', $data['appointment_window']);

        }

        if ($data['send_to_pso']) {

            $payload = Arr::add($payload, 'username', $data['username']);
            $payload = Arr::add($payload, 'password', $data['password']);
            $payload = Arr::add($payload, 'account_id', $data['account_id']);

        }

        return $payload;
    }


}
