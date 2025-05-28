# Voice API Improvements

This document outlines the improvements made to the Voice Order API endpoints for better performance, stability, and user experience.

## New Route Added

- Added a new route for testing audio transcription: `/api/voice-order/test-transcribe`

## Rate Limiting

Added rate limiting to prevent abuse:

- `/voice-order` - limited to 20 requests per minute
- `/voice-order/realtime-transcription` - limited to 30 requests per minute 
- `/voice-order/repeat` - limited to 30 requests per minute
- `/voice-order/test-transcribe` - limited to 20 requests per minute

## Response Enhancements

- Added `log_id` to all Voice API responses to facilitate easier feedback collection
- Added `cached` indicator in responses when transcription was served from cache

## Caching Implementation

- Added caching for audio transcriptions:
  - Transcriptions are cached by audio file hash for 2 minutes
  - Reduces processing time and cost for duplicate requests
  - Improves response times for similar or identical audio files

## Database Updates

- Added a new migration to add a JSON `feedback` column to the `ai_assistant_logs` table:
  - Structured storage of user feedback with timestamps
  - Additional metadata to improve voice recognition quality analysis

## Enhanced Controller Methods

- Updated `transcribeAudio()` method to accept direct HTTP requests
- Improved logging for all endpoints with standardized format
- Added metadata fields for analytics

## Usage

### Test Transcription Endpoint

```
POST /api/voice-order/test-transcribe
Content-Type: multipart/form-data

Parameters:
- audio: [audio file]
- language: en-US (default)

Response:
{
  "success": true,
  "text": "transcribed text here",
  "confidence": 0.92,
  "language": "en-US",
  "timestamps": [...],
  "cached": false
}
```

### Feedback Collection

```
POST /api/voice-order/feedback
Content-Type: application/json

{
  "log_id": 123,  // The log_id returned from any voice API response
  "helpful": true,
  "feedback": "The transcription was accurate but the food recommendations could be improved."
}
``` 