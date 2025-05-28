<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\VoiceDialogueService;
use App\Services\VoiceOrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class VoiceDialogueController extends Controller
{
    /**
     * @var VoiceDialogueService
     */
    protected $dialogueService;
    
    /**
     * Constructor
     */
    public function __construct(VoiceDialogueService $dialogueService = null)
    {
        $this->dialogueService = $dialogueService ?? new VoiceDialogueService();
    }
    
    /**
     * Process a voice command
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function processVoiceCommand(Request $request)
    {
        // Validate the request
        $validator = Validator::make($request->all(), [
            'audio' => 'required|file|mimes:webm,wav,mp3,ogg,flac',
            'language' => 'sometimes|string',
            'dialogue_state' => 'sometimes|array',
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }
        
        // Get dialogue state if provided
        if ($request->has('dialogue_state')) {
            $this->dialogueService->setDialogueState($request->input('dialogue_state'));
        }
        
        // Get audio file
        $audio = $request->file('audio');
        $language = $request->input('language', 'en-US');
        
        // Store audio file temporarily
        $path = $audio->storeAs('temp', 'voice_' . Str::uuid() . '.' . $audio->getClientOriginalExtension(), 'local');
        $fullPath = storage_path('app/' . $path);
        
        try {
            // Process the voice command
            $result = $this->dialogueService->processVoiceCommand($fullPath, $language);
            
            // Clean up temporary file
            Storage::disk('local')->delete($path);
            
            return response()->json($result);
        } catch (\Exception $e) {
            // Clean up temporary file
            Storage::disk('local')->delete($path);
            
            return response()->json([
                'success' => false,
                'message' => 'Error processing voice command: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get available payment methods
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPaymentMethods()
    {
        try {
            $dialogueState = $this->dialogueService->getDialogueState();
            $paymentMethods = $dialogueState['payment_methods'] ?? [];
            
            return response()->json([
                'success' => true,
                'payment_methods' => $paymentMethods
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error getting payment methods: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get active currency and available currencies
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCurrencies()
    {
        try {
            $dialogueState = $this->dialogueService->getDialogueState();
            $activeCurrency = $dialogueState['currency'] ?? null;
            $availableCurrencies = $dialogueState['available_currencies'] ?? [];
            
            return response()->json([
                'success' => true,
                'active_currency' => $activeCurrency,
                'available_currencies' => $availableCurrencies
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error getting currencies: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get cart contents
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCart()
    {
        try {
            $dialogueState = $this->dialogueService->getDialogueState();
            $cart = $dialogueState['cart'] ?? [];
            
            return response()->json([
                'success' => true,
                'cart' => $cart
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error getting cart: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Reset dialogue state
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function resetDialogue()
    {
        try {
            // Create a new dialogue service instance to reset the state
            $this->dialogueService = new VoiceDialogueService();
            
            return response()->json([
                'success' => true,
                'message' => 'Dialogue state reset successfully',
                'dialogue_state' => $this->dialogueService->getDialogueState()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error resetting dialogue: ' . $e->getMessage()
            ], 500);
        }
    }
} 