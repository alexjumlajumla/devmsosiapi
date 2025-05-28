<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ListFcmTokens extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fcm:list-tokens 
                            {--user= : Filter by user ID}
                            {--email= : Filter by user email}
                            {--has-tokens : Only show users with FCM tokens}
                            {--no-tokens : Only show users without FCM tokens}
                            {--limit=20 : Number of users to display per page}
                            {--page=1 : Page number}
                            {--export : Export results as JSON}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List users and their FCM tokens';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $query = User::query()
            ->select([
                'id',
                'firstname',
                'lastname',
                'email',
                'phone',
                'firebase_token',
                'created_at',
                'updated_at'
            ])
            ->when($this->option('user'), function (Builder $query, $userId) {
                return $query->where('id', $userId);
            })
            ->when($this->option('email'), function (Builder $query, $email) {
                return $query->where('email', 'like', "%{$email}%");
            })
            ->when($this->option('has-tokens'), function (Builder $query) {
                return $query->whereNotNull('firebase_token')
                    ->where('firebase_token', '!=', '[]');
            })
            ->when($this->option('no-tokens'), function (Builder $query) {
                return $query->whereNull('firebase_token')
                    ->orWhere('firebase_token', '=', '[]');
            });

        $total = $query->count();
        $perPage = (int) $this->option('limit');
        $currentPage = max(1, (int) $this->option('page'));
        $totalPages = ceil($total / $perPage);

        $users = $query->forPage($currentPage, $perPage)->get();

        if ($this->option('export')) {
            $exportData = $users->map(function ($user) {
                $tokens = $user->firebase_token ?? [];
                return [
                    'id' => $user->id,
                    'name' => trim($user->firstname . ' ' . $user->lastname),
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'token_count' => is_array($tokens) ? count($tokens) : 0,
                    'created_at' => $user->created_at->toDateTimeString(),
                    'updated_at' => $user->updated_at->toDateTimeString(),
                ];
            });

            $this->line($exportData->toJson(JSON_PRETTY_PRINT));
            return 0;
        }

        $this->info(sprintf(
            'Showing %d to %d of %d users (Page %d of %d)',
            ($currentPage - 1) * $perPage + 1,
            min($currentPage * $perPage, $total),
            $total,
            $currentPage,
            max(1, $totalPages)
        ));

        $this->newLine();

        $headers = [
            'ID',
            'Name',
            'Email',
            'Phone',
            'Tokens',
            'Created',
            'Updated'
        ];

        $rows = $users->map(function ($user) {
            $tokens = $user->firebase_token ?? [];
            $tokenCount = is_array($tokens) ? count($tokens) : 0;
            
            return [
                $user->id,
                trim($user->firstname . ' ' . $user->lastname),
                $user->email,
                $user->phone ?? '-',
                $tokenCount > 0 ? 
                    ($tokenCount . ' token' . ($tokenCount !== 1 ? 's' : '')) : 
                    'No tokens',
                $user->created_at->diffForHumans(),
                $user->updated_at->diffForHumans(),
            ];
        });

        $this->table($headers, $rows);

        if ($totalPages > 1) {
            $this->newLine();
            $this->line('Use --page=<number> to view different pages');
            $this->line('Use --limit=<number> to change items per page');
        }

        $this->newLine();
        $this->line('Use --export to get results in JSON format');
        $this->line('Use --has-tokens to filter users with FCM tokens');
        $this->line('Use --no-tokens to filter users without FCM tokens');
        $this->line('Use --user=<id> to filter by user ID');
        $this->line('Use --email=<email> to filter by email');

        return 0;
    }
}
