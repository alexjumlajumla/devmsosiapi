# VFD (Virtual Fiscal Device) Integration

This document provides information on how to set up and use the VFD (Virtual Fiscal Device) integration for generating fiscal receipts in Tanzania.

## Table of Contents

1. [Prerequisites](#prerequisites)
2. [Installation](#installation)
3. [Configuration](#configuration)
4. [Usage](#usage)
5. [API Endpoints](#api-endpoints)
6. [Testing](#testing)
7. [Troubleshooting](#troubleshooting)
8. [License](#license)

## Prerequisites

- PHP 8.0 or higher
- Laravel 9.x or higher
- Composer
- VFD API credentials (provided by TRA)
- SSL Certificate for secure communication with VFD API

## Installation

1. Add the VFD service provider to your `config/app.php`:

```php
'providers' => [
    // ...
    App\Providers\VfdServiceProvider::class,
],
```

2. Publish the configuration (if needed):

```bash
php artisan vendor:publish --provider="App\Providers\VfdServiceProvider" --tag=config
```

3. Run migrations:

```bash
php artisan migrate
```

## Configuration

### Basic Configuration

Add the following environment variables to your `.env` file:

```env
# VFD API Configuration
VFD_BASE_URL=https://api.vfd.tz
VFD_API_KEY=your_api_key_here
VFD_TIN=your_tin_number
VFD_CERT_PATH=/path/to/your/cert.pem
VFD_TIMEOUT=30
VFD_RETRY_ATTEMPTS=3
VFD_RETRY_DELAY=5

# Archive Service Configuration (optional but recommended for production)
VFD_ARCHIVE_ENABLED=true
VFD_ARCHIVE_ENDPOINT=https://archive.example.com/api/receipts
VFD_ARCHIVE_API_KEY=your_archive_api_key_here
VFD_ARCHIVE_VERIFY_SSL=true

# Notification Settings
VFD_NOTIFICATION_SMS_ENABLED=true
VFD_NOTIFICATION_EMAIL_ENABLED=false
VFD_NOTIFICATION_EMAIL_ADDRESS=receipts@example.com

# Queue Configuration
QUEUE_CONNECTION=redis  # or 'database' if Redis is not available
```

### Queue Workers

For optimal performance, configure queue workers to process VFD receipts and archive jobs asynchronously:

```bash
# Process VFD receipt generation (high priority)
nohup php artisan queue:work --queue=vfd-receipts --tries=3 --timeout=300 > storage/logs/vfd-receipts.log &

# Process VFD archive sync (lower priority)
nohup php artisan queue:work --queue=vfd-archive --tries=3 --delay=60 --timeout=600 > storage/logs/vfd-archive.log &

# Keep this process running with a process manager like Supervisor

# For development, you can use:
php artisan queue:work --queue=vfd-receipts,vfd-archive,default --tries=3 --timeout=300
```

### Supervisor Configuration (Production)

Create a new supervisor config file at `/etc/supervisor/conf.d/vfd-worker.conf`:

```ini
[program:vfd-receipts]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/your/app/artisan queue:work --queue=vfd-receipts --tries=3 --timeout=300
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/path/to/your/app/storage/logs/vfd-receipts.log

[program:vfd-archive]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/your/app/artisan queue:work --queue=vfd-archive --tries=3 --delay=60 --timeout=600
autostart=true
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/path/to/your/app/storage/logs/vfd-archive.log
```

Then update supervisor and restart:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start vfd-*:*
```

## Usage

### Generating a Receipt

To generate a VFD receipt for an order, use the `VfdReceiptService`:

```php
use App\Services\Order\VfdReceiptService;

$vfdService = app(VfdReceiptService::class);
$result = $vfdService->generateForOrder($order, 'card');

if ($result['status']) {
    $receipt = $result['data'];
    echo "Receipt generated: " . $receipt->receipt_number;
} else {
    echo "Error: " . $result['message'];
}
```

### Checking Receipt Status

```php
$receipt = $vfdService->getForOrder($order);

if ($receipt) {
    echo "Receipt status: " . $receipt->status;
    echo "Receipt URL: " . $receipt->receipt_url;
} else {
    echo "No receipt found for this order.";
}
```

## API Endpoints

### Get Receipt for Order

```
GET /api/v1/user/orders/{orderId}/receipt
```

**Response**

```json
{
    "data": {
        "id": 1,
        "receipt_number": "VFD-1234567890",
        "receipt_url": "https://vfd.tz/receipts/VFD-1234567890",
        "amount": 5000,
        "status": "generated",
        "created_at": "2023-05-31T12:00:00.000000Z"
    }
}
```

### Generate Receipt for Order

```
POST /api/v1/user/orders/{orderId}/receipt/generate
```

**Response**

```json
{
    "message": "Receipt generation queued",
    "data": {
        "id": 1,
        "receipt_number": "VFD-1234567890",
        "status": "pending",
        "created_at": "2023-05-31T12:00:00.000000Z"
    }
}
```

## Testing

### Run Tests

```bash
# Run all VFD-related tests
php artisan test tests/Feature/VfdServiceTest.php

# Test the VFD service
php artisan vfd:test {orderId}

# Test the archive service connection
php artisan vfd:test-archive
```

### Test VFD Connection

```bash
php artisan vfd:test {orderId}
```

Example:

```bash
php artisan vfd:test 123
```

### Retry Failed Receipts

To retry generating failed VFD receipts:

```bash
php artisan vfd:retry-failed --limit=10
```

## Maintenance Commands

### Monitor VFD Receipts

```bash
# Show status of recent receipts
php artisan vfd:monitor --hours=24 --status=all

# Test VFD API connection
php artisan vfd:test {orderId}

# Test archive service connection
php artisan vfd:test-archive

# Retry failed receipt generation
php artisan vfd:retry-failed --limit=10

# Clean up old receipts (older than 90 days by default)
php artisan vfd:cleanup --days=90 --force

# Sync receipts to archive
php artisan vfd:sync-archive --days=7 --limit=100 --retry-failed

# Dry run to see what would be synced
php artisan vfd:sync-archive --days=7 --dry-run
```

### Monitoring and Alerts

Set up monitoring for these key metrics:

1. Queue lengths for `vfd-receipts` and `vfd-archive`
2. Failed job counts
3. Sync error rates
4. Average processing time

Example alert rules (for Prometheus/Grafana):

```yaml
# Alert for growing queue
- alert: VFDQueueBacklog
  expr: redis_queue_jobs > 100
  for: 15m
  labels:
    severity: warning
  annotations:
    summary: "VFD queue backlog is high"
    description: "The {{ $labels.queue }} queue has {{ $value }} pending jobs"

# Alert for failed jobs
- alert: VFDSyncErrors
  expr: rate(laravel_queue_failed_jobs_total[5m]) > 0
  for: 5m
  labels:
    severity: critical
  annotations:
    summary: "VFD sync errors detected"
    description: "{{ $value }} VFD sync jobs have failed in the last 5 minutes"
```

### Backup and Recovery

1. **Database Backups**: Ensure the `vfd_receipts` table is included in your regular database backups
2. **Receipt Storage**: If storing receipt PDFs, include them in your backup strategy
3. **Disaster Recovery**: Test restoring from backups regularly

### Security Considerations

1. **API Keys**: Rotate VFD and archive API keys regularly
2. **TLS**: Always use HTTPS for archive service communication
3. **Access Control**: Restrict access to VFD and archive service credentials
4. **Audit Logging**: Monitor access to VFD-related endpoints and commands

## Troubleshooting

### Common Issues

1. **Certificate Errors**
   - Ensure the certificate file exists and is readable by the web server
   - Verify the certificate is in PEM format
   - Check certificate permissions

2. **API Connection Issues**
   - Verify the base URL is correct
   - Check if the API key is valid
   - Ensure the server can reach the VFD API (check firewall settings)

3. **Receipt Generation Failures**
   - Check the `vfd_receipts` table for error messages
   - Verify the order has a delivery fee
   - Check if a receipt already exists for the order

## License

This module is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
