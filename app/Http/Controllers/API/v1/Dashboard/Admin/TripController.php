<?php

namespace App\Http\Controllers\API\v1\Dashboard\Admin;

use App\Http\Controllers\API\v1\Dashboard\Admin\AdminBaseController;
use App\Models\Trip;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\TripService\RouteOptimizer;

class TripController extends AdminBaseController
{
    public function index(): JsonResponse
    {
        $trips = Trip::with('locations')->latest()->paginate(20);
        return $this->successResponse('success', $trips);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'nullable|string',
            'start_address' => 'required|string',
            'start_lat' => 'required|numeric',
            'start_lng' => 'required|numeric',
            'vehicle_id' => 'nullable|exists:delivery_vehicles,id',
            'driver_id' => 'nullable|exists:users,id',
            'scheduled_at' => 'nullable|date',
            'locations' => 'required|array|min:1',
            'locations.*.address' => 'required|string',
            'locations.*.lat' => 'required|numeric',
            'locations.*.lng' => 'required|numeric',
        ]);

        $trip = Trip::create($data);
        foreach ($data['locations'] as $idx => $loc) {
            $trip->locations()->create([
                'address' => $loc['address'],
                'lat' => $loc['lat'],
                'lng' => $loc['lng'],
                'sequence' => $idx,
            ]);
        }

        return $this->successResponse('created', $trip->load('locations'));
    }

    public function optimize(Request $request, Trip $trip, RouteOptimizer $optimizer): JsonResponse
    {
        $order = $optimizer->optimise($trip);

        // Update sequences if we got a valid order
        foreach ($order as $seq => $locId) {
            $trip->locations()->where('id', $locId)->update(['sequence' => $seq]);
        }

        // Save optimisation meta
        $trip->update(['meta' => array_merge($trip->meta ?? [], ['optimized_at' => now()])]);

        return $this->successResponse('optimized', $trip->load('locations'));
    }

    public function show(Request $request, $id): JsonResponse
    {
        try {
            $trip = Trip::with(['locations' => function($query) {
                $query->orderBy('sequence');
            }, 'driver', 'vehicle'])->findOrFail($id);

            // If trip locations don't exist or are empty, provide sensible defaults
            if (!$trip->locations || $trip->locations->isEmpty()) {
                $trip->setRelation('locations', collect([]));
            }

            // Add additional data needed for the map view
            $data = [
                'trip' => $trip,
                'route_lines' => $this->generateRouteLines($trip),
            ];

            return $this->successResponse('success', $data);
        } catch (\Exception $e) {
            \Log::error('Error fetching trip details', [
                'trip_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return $this->errorResponse('Failed to fetch trip details: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Generate route lines for map display
     */
    private function generateRouteLines(Trip $trip): array
    {
        $routeLines = [];
        $locations = $trip->locations;
        
        if ($locations->isEmpty()) {
            return $routeLines;
        }
        
        // Start from the beginning point
        $previousPoint = [
            'lat' => (float) $trip->start_lat,
            'lng' => (float) $trip->start_lng,
        ];
        
        // Connect each point in sequence
        foreach ($locations as $location) {
            $currentPoint = [
                'lat' => (float) $location->lat,
                'lng' => (float) $location->lng,
            ];
            
            $routeLines[] = [
                'from' => $previousPoint,
                'to' => $currentPoint,
                'status' => $location->status,
            ];
            
            $previousPoint = $currentPoint;
        }
        
        return $routeLines;
    }

    public function optimizationLogs(): JsonResponse
    {
        $logs = Trip::whereNotNull('meta->optimized_at')
            ->orderBy('meta->optimized_at', 'desc')
            ->limit(50)
            ->get(['id', 'name', 'meta', 'created_at']);

        $logData = [];
        foreach ($logs as $trip) {
            $optimizedAt = $trip->meta['optimized_at'] ?? null;
            if ($optimizedAt) {
                $logData[] = [
                    'id' => $trip->id,
                    'name' => $trip->name ?? "Trip #{$trip->id}",
                    'optimized_at' => $optimizedAt,
                    'optimization_metrics' => $trip->meta['optimization_metrics'] ?? null,
                    'created_at' => $trip->created_at,
                ];
            }
        }

        return $this->successResponse('optimization logs', $logData);
    }
} 