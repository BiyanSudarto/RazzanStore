<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class AdminAuditLogger
{
    public function record(
        string $action,
        Model $target,
        ?array $before = null,
        ?array $after = null,
    ): AdminAuditLog {
        $request = request();

        return AdminAuditLog::create([
            'admin_id' => Auth::guard('admin')->id(),
            'action' => $action,
            'target_type' => $target::class,
            'target_id' => $target->getKey(),
            'before_data' => $this->redact($before),
            'after_data' => $this->redact($after),
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'created_at' => now(),
        ]);
    }

    private function redact(?array $data): ?array
    {
        if ($data === null) {
            return null;
        }

        $sensitive = ['password', 'secret', 'token', 'api_key', 'server_key'];

        foreach ($data as $key => $value) {
            $normalized = strtolower((string) $key);

            foreach ($sensitive as $needle) {
                if (str_contains($normalized, $needle)) {
                    unset($data[$key]);

                    continue 2;
                }
            }

            if (is_array($value)) {
                $data[$key] = $this->redact($value);
            }
        }

        return $data;
    }
}
