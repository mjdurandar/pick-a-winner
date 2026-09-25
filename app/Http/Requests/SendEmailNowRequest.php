<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A "Send emails" request: which of the three alerts to send, and the To and CC
 * they all go to.
 *
 * film_check and sheet_source are a single id or "all"; weekly_period picks the
 * week. Each is left out when that email is not wanted — at least one must be
 * there. To and CC arrive as whatever the person typed — comma, semicolon or newline
 * separated — and are split here, so a typo is reported against the address
 * that is wrong rather than as one unreadable string.
 */
class SendEmailNowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'to' => self::split($this->input('to')),
            'cc' => self::split($this->input('cc')),
        ]);
    }

    public function rules(): array
    {
        return [
            'to' => 'required|array|min:1|max:20',
            'to.*' => 'email',
            'cc' => 'array|max:20',
            'cc.*' => 'email',
            'film_check' => ['nullable', 'required_without_all:sheet_source,weekly_period', 'regex:/^(all|\d+)$/'],
            'sheet_source' => ['nullable', 'regex:/^(all|\d+)$/'],
            'weekly_period' => 'nullable|in:this_week,last_week',
        ];
    }

    public function messages(): array
    {
        return [
            'film_check.required_without_all' => 'Tick at least one email to send.',
            'to.required' => 'Enter at least one address to send to.',
            'to.*.email' => ':input is not an email address.',
            'cc.*.email' => ':input is not an email address.',
        ];
    }

    /** @return list<string> */
    public function recipients(): array
    {
        return $this->validated('to');
    }

    /**
     * CC without anyone already in To — they would only get a second copy.
     *
     * @return list<string>
     */
    public function ccRecipients(): array
    {
        $to = array_map('strtolower', $this->recipients());

        return array_values(array_filter(
            $this->validated('cc') ?? [],
            fn (string $address) => ! in_array(strtolower($address), $to, true)
        ));
    }

    /** @return list<string> */
    protected static function split(mixed $value): array
    {
        if (is_array($value)) {
            $value = implode(',', $value);
        }

        $parts = preg_split('/[\s,;]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique($parts));
    }
}
