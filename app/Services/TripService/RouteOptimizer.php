<?php

namespace App\Services\TripService;

use App\Models\Trip;
use App\Models\TripLocation;
use Orhanerday\OpenAi\OpenAi;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class RouteOptimizer
{
    protected ?string $apiKey;

    public function __construct()
    {
        $this->apiKey = config('services.openai.api_key');
    }

    /**
     * Optimise the trip visiting order.
     * Returns ordered location IDs.
     */
    public function optimise(Trip $trip): array
    {
        $locations = $trip->locations()->get();
        $startTime = microtime(true);

        // Build cache key based on trip id & location hash (lat,lng,updated_at)
        $hash = md5($locations->map(fn ($l) => $l->lat . ',' . $l->lng . '|' . $l->updated_at)->implode(';'));
        $cacheKey = 'optimized_trip_' . $trip->id . '_' . $hash;

        $route = Cache::remember($cacheKey, now()->addMinutes(10), function () use ($trip, $locations) {
            // Try AI optimisation first
            if ($this->apiKey) {
                try {
                    $ordered = $this->optimiseWithOpenAi($trip, $locations);
                    if ($ordered) {
                        return [
                            'route' => $ordered,
                            'method' => 'openai',
                        ];
                    }
                } catch (\Throwable $e) {
                    // Log but silently fall back
                    logger()->warning('OpenAI route optimisation failed: ' . $e->getMessage());
                }
            }

            // Fallback heuristic
            return [
                'route' => $this->optimiseNearestNeighbour($trip, $locations),
                'method' => 'nearest_neighbour',
            ];
        });

        // Calculate metrics
        $endTime = microtime(true);
        $timeTaken = round(($endTime - $startTime) * 1000); // ms
        $totalLocations = $locations->count();
        
        // Save optimization metrics in trip meta
        $meta = $trip->meta ?? [];
        $meta['optimized_at'] = now()->toIso8601String();
        $meta['optimization_metrics'] = [
            'time_ms' => $timeTaken,
            'method' => $route['method'],
            'locations_count' => $totalLocations,
            'hash' => $hash,
        ];
        
        $trip->update(['meta' => $meta]);
        
        return $route['route'];
    }

    /**
     * Use OpenAI GPT model to suggest an efficient visiting order.
     * Expected to return array of location IDs.
     */
    protected function optimiseWithOpenAi(Trip $trip, Collection $locations): array
    {
        $openAi = new OpenAi($this->apiKey);

        $data = [
            'start' => [
                'lat' => $trip->start_lat,
                'lng' => $trip->start_lng,
            ],
            'locations' => $locations->map(function (TripLocation $loc) {
                return [
                    'id' => $loc->id,
                    'lat' => $loc->lat,
                    'lng' => $loc->lng,
                ];
            })->values()->toArray(),
        ];

        $prompt = "You are a logistics expert. Given the following JSON payload, return ONLY a JSON array containing the IDs of the locations in the most time-efficient visiting order starting after the start point. Do not include any other text. JSON: ".json_encode($data);

        $response = $openAi->chatCompletion([
            'model' => 'gpt-3.5-turbo',
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt,
                ],
            ],
            'temperature' => 0.2,
            'max_tokens' => 100,
        ]);

        $decoded = json_decode($response, true);
        $content = data_get($decoded, 'choices.0.message.content');
        $ids = json_decode($content, true);

        // Ensure we got an array of ids matching the provided ones
        if (is_array($ids) && !array_diff($ids, $locations->pluck('id')->toArray())) {
            return $ids;
        }

        // Fallback
        return [];
    }

    /**
     * Simple nearest-neighbour heuristic from start point.
     */
    protected function optimiseNearestNeighbour(Trip $trip, Collection $locations): array
    {
        $remaining = $locations->keyBy('id');
        $order = [];

        $currentLat = $trip->start_lat;
        $currentLng = $trip->start_lng;

        while ($remaining->isNotEmpty()) {
            $next = $remaining->minBy(function (TripLocation $loc) use ($currentLat, $currentLng) {
                return $this->distance($currentLat, $currentLng, $loc->lat, $loc->lng);
            });

            $order[] = $next->id;
            $currentLat = $next->lat;
            $currentLng = $next->lng;
            $remaining->forget($next->id);
        }

        return $order;
    }

    protected function distance($lat1, $lon1, $lat2, $lon2): float
    {
        // Haversine formula
        $earthRadius = 6371; // km
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2) * sin($dLon/2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadius * $c;
    }
} 