<?php

namespace App\Http\Controllers\API\v1\Auth\Driver;

use App\Http\Controllers\Controller;
use App\Models\Trip;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LocationController extends Controller
{
    /**
     * Update driver location for active trip
     */
    public function updateLocation(Request $request): JsonResponse
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json(['status' => false, 'message' => 'Unauthenticated'], 401);
        }

        // Validate request
        $data = $request->validate([
            'lat' => 'required|numeric',
            'lng' => 'required|numeric',
            'speed' => 'nullable|numeric',
            'bearing' => 'nullable|numeric',
            'accuracy' => 'nullable|numeric',
            'trip_id' => 'required|integer|exists:trips,id',
        ]);

        // Get the trip and check if this driver is assigned to it
        $trip = Trip::where('id', $data['trip_id'])
            ->where('driver_id', $user->id)
            ->where('status', 'in_progress')
            ->first();

        if (!$trip) {
            return response()->json([
                'status' => false,
                'message' => 'You are not assigned to this trip or the trip is not in progress'
            ], 404);
        }

        // Forward the request to admin API
        try {
            $response = Http::post(route('api.dashboard.admin.trip.tracking.location.update', ['trip' => $trip->id]), [
                'lat' => $data['lat'],
                'lng' => $data['lng'],
                'speed' => $data['speed'] ?? null,
                'bearing' => $data['bearing'] ?? null,
                'accuracy' => $data['accuracy'] ?? null,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Location updated successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating driver location', [
                'driver_id' => $user->id,
                'trip_id' => $trip->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Failed to update location',
            ], 500);
        }
    }

    /**
     * Get current trip information
     */
    public function currentTrip(): JsonResponse
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json(['status' => false, 'message' => 'Unauthenticated'], 401);
        }

        // Get the active trip for this driver
        $trip = Trip::where('driver_id', $user->id)
            ->whereIn('status', ['planned', 'in_progress'])
            ->with(['locations' => function ($query) {
                $query->orderBy('sequence');
            }])
            ->first();

        if (!$trip) {
            return response()->json([
                'status' => false,
                'message' => 'No active trip found',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'Current trip retrieved successfully',
            'data' => $trip,
        ]);
    }

    /**
     * Start a trip
     */
    public function startTrip(Request $request): JsonResponse
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json(['status' => false, 'message' => 'Unauthenticated'], 401);
        }

        $data = $request->validate([
            'trip_id' => 'required|integer|exists:trips,id',
        ]);

        // Get the trip and check if this driver is assigned to it
        $trip = Trip::where('id', $data['trip_id'])
            ->where('driver_id', $user->id)
            ->where('status', 'planned')
            ->first();

        if (!$trip) {
            return response()->json([
                'status' => false,
                'message' => 'You are not assigned to this trip or the trip is not in planned status'
            ], 404);
        }

        // Forward the request to admin API
        try {
            $response = Http::post(route('api.dashboard.admin.trip.tracking.start', ['trip' => $trip->id]));
            
            return response()->json([
                'status' => true,
                'message' => 'Trip started successfully',
                'data' => $trip->fresh(),
            ]);
        } catch (\Exception $e) {
            Log::error('Error starting trip', [
                'driver_id' => $user->id,
                'trip_id' => $trip->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Failed to start trip',
            ], 500);
        }
    }

    /**
     * Complete the current stop
     */
    public function completeCurrentStop(Request $request): JsonResponse
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json(['status' => false, 'message' => 'Unauthenticated'], 401);
        }

        $data = $request->validate([
            'trip_id' => 'required|integer|exists:trips,id',
            'location_id' => 'required|integer|exists:trip_locations,id',
        ]);

        // Get the trip and check if this driver is assigned to it
        $trip = Trip::where('id', $data['trip_id'])
            ->where('driver_id', $user->id)
            ->where('status', 'in_progress')
            ->first();

        if (!$trip) {
            return response()->json([
                'status' => false,
                'message' => 'You are not assigned to this trip or the trip is not in progress'
            ], 404);
        }

        // Check if the location belongs to this trip
        $location = $trip->locations()
            ->where('id', $data['location_id'])
            ->where('status', 'pending')
            ->first();

        if (!$location) {
            return response()->json([
                'status' => false,
                'message' => 'Location not found or already completed'
            ], 404);
        }

        // Mark the location as arrived
        $location->update([
            'status' => 'arrived',
            'arrived_at' => now(),
        ]);

        // Check if this was the last stop
        $pendingCount = $trip->locations()->where('status', 'pending')->count();
        if ($pendingCount === 0) {
            // Update trip status to completed
            $trip->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);
        }

        return response()->json([
            'status' => true,
            'message' => 'Stop completed successfully',
            'data' => [
                'location' => $location->fresh(),
                'is_last_stop' => $pendingCount === 0,
                'trip' => $trip->fresh(),
            ],
        ]);
    }
} 