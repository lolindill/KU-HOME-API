<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateHousekeepingTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // 🌟 Phase A: status enum ใหม่ — unassigned|accepted|in_progress|done
            // (transition ผ่าน state machine เท่านั้น — endpoint PATCH /tasks/{id}/status)
            'status' => 'sometimes|string|in:unassigned,accepted,in_progress,done',
            'task_type' => 'sometimes|string|in:pre_checkin,checkout,checkout_then_in,daily,monthly,group',
            'notes' => 'nullable|string',
            'scheduled_for' => 'nullable|date',
        ];
    }
}
