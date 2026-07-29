<?php

namespace App\Services\TurkishAirlines;

interface TurkishAirlinesMcpGateway
{
    /**
     * @return array{configured: bool, status: string, message: string}
     */
    public function status(): array;

    /**
     * @param  array<string, mixed>  $parameters
     * @return array{status: string, message?: string, raw?: array<string, mixed>, items?: array<int, array<string, mixed>>}
     */
    public function search(array $parameters): array;
}
