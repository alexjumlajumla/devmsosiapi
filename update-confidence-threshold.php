<?php
/**
 * Voice Order Confidence Threshold Adjustment Script
 * 
 * This script modifies the default confidence threshold for voice order processing
 * to ensure that even low confidence transcriptions are still processed and recommendations
 * are provided to users, with appropriate confidence indicators.
 */

// Bootstrap Laravel
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

// Log the start of the script
Log::info('Starting confidence threshold adjustment script');

// Set the new confidence threshold in cache
Cache::put('voice_order.min_confidence_threshold', 0.3, 86400 * 30); // Store for 30 days

// Add a configuration to decide how to handle low confidence transcriptions
Cache::put('voice_order.process_low_confidence', true, 86400 * 30); // Store for 30 days

// Add tiered confidence levels for UI display
$confidenceTiers = [
    'very_low' => 0.3,   // Process but show fallback UI
    'low' => 0.5,        // Process but show warning
    'medium' => 0.7,     // Process normally
    'high' => 0.9        // High confidence
];
Cache::put('voice_order.confidence_tiers', $confidenceTiers, 86400 * 30);

// Instructions for modifying VoiceOrderController manually
echo "====================================================================\n";
echo "CONFIDENCE THRESHOLD ADJUSTMENT COMPLETE\n";
echo "====================================================================\n\n";
echo "New values stored in cache:\n";
echo "- Minimum confidence threshold: 0.3\n";
echo "- Process low confidence transcriptions: Yes\n";
echo "- Confidence tiers for UI:\n";
echo "  * Very Low: < 0.3\n";
echo "  * Low: 0.3 - 0.5\n";
echo "  * Medium: 0.5 - 0.7\n";
echo "  * High: > 0.7\n\n";

echo "To make this adjustment permanent, you should modify the VoiceOrderController.php\n";
echo "Find any lines checking for confidence_score threshold and reduce to 0.3.\n";
echo "For example, change:\n\n";
echo "if (\$transcription['confidence'] < 0.6) {\n";
echo "    // Handle low confidence\n";
echo "}\n\n";
echo "To:\n\n";
echo "if (\$transcription['confidence'] < 0.3) {\n";
echo "    // Handle very low confidence\n";
echo "}\n\n";

echo "====================================================================\n";

Log::info('Confidence threshold adjustment script completed'); 