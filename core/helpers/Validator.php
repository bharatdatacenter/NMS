<?php

declare(strict_types=1);

namespace NMS\Core\Helpers;

/**
 * Field validation helper.
 * Supports: required, type, regex, ip, cidr, min, max, enum, email
 */
class Validator
{
    private array $errors = [];

    /**
     * Validate data against rules.
     *
     * @param  array $data  Input data
     * @param  array $rules Rule map: ['field' => 'rule1|rule2:arg']
     * @return array        Validated + filtered data
     * @throws \InvalidArgumentException if validation fails (with errors accessible via getErrors())
     */
    public function validate(array $data, array $rules): array
    {
        $this->errors = [];
        $validated = [];

        foreach ($rules as $field => $ruleStr) {
            $fieldRules = explode('|', $ruleStr);
            $value = $data[$field] ?? null;

            foreach ($fieldRules as $rule) {
                [$ruleName, $ruleArg] = array_pad(explode(':', $rule, 2), 2, null);

                if ($ruleName === 'required') {
                    if ($value === null || $value === '') {
                        $this->errors[$field][] = "The {$field} field is required.";
                    }
                    continue;
                }

                if ($value === null || $value === '') {
                    continue; // Skip non-required fields if empty
                }

                match ($ruleName) {
                    'string'  => is_string($value) || $this->addError($field, "must be a string"),
                    'integer' => (is_int($value) || ctype_digit((string)$value)) || $this->addError($field, "must be an integer"),
                    'boolean' => is_bool($value) || $this->addError($field, "must be a boolean"),
                    'array'   => is_array($value) || $this->addError($field, "must be an array"),
                    'numeric' => is_numeric($value) || $this->addError($field, "must be numeric"),
                    'email'   => filter_var($value, FILTER_VALIDATE_EMAIL) || $this->addError($field, "must be a valid email"),
                    'ip'      => $this->validateIP($field, $value),
                    'cidr'    => $this->validateCIDR($field, $value),
                    'ipv4'    => (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) || $this->addError($field, "must be a valid IPv4 address"),
                    'ipv6'    => (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) || $this->addError($field, "must be a valid IPv6 address"),
                    'regex'   => preg_match($ruleArg, (string)$value) || $this->addError($field, "format is invalid"),
                    'min'     => (strlen((string)$value) >= (int)$ruleArg) || $this->addError($field, "must be at least {$ruleArg} characters"),
                    'max'     => (strlen((string)$value) <= (int)$ruleArg) || $this->addError($field, "must not exceed {$ruleArg} characters"),
                    'min_val' => ($value >= (int)$ruleArg) || $this->addError($field, "must be at least {$ruleArg}"),
                    'max_val' => ($value <= (int)$ruleArg) || $this->addError($field, "must not exceed {$ruleArg}"),
                    'enum'    => in_array($value, explode(',', $ruleArg), true) || $this->addError($field, "must be one of: {$ruleArg}"),
                    default   => null,
                };
            }

            if (array_key_exists($field, $data)) {
                $validated[$field] = $value;
            }
        }

        return $validated;
    }

    public function fails(): bool
    {
        return !empty($this->errors);
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getFirstError(): string
    {
        foreach ($this->errors as $fieldErrors) {
            return $fieldErrors[0];
        }
        return '';
    }

    private function addError(string $field, string $message): bool
    {
        $this->errors[$field][] = "The {$field} field {$message}.";
        return false;
    }

    private function validateIP(string $field, mixed $value): bool
    {
        if (filter_var($value, FILTER_VALIDATE_IP) === false) {
            $this->addError($field, "must be a valid IP address (IPv4 or IPv6)");
            return false;
        }
        return true;
    }

    private function validateCIDR(string $field, mixed $value): bool
    {
        if (!is_string($value) || !str_contains($value, '/')) {
            $this->addError($field, "must be a valid CIDR notation");
            return false;
        }
        [$ip, $prefix] = explode('/', $value, 2);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            if (!ctype_digit($prefix) || (int)$prefix < 0 || (int)$prefix > 32) {
                $this->addError($field, "must be a valid IPv4 CIDR (prefix 0-32)");
                return false;
            }
        } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            if (!ctype_digit($prefix) || (int)$prefix < 0 || (int)$prefix > 128) {
                $this->addError($field, "must be a valid IPv6 CIDR (prefix 0-128)");
                return false;
            }
        } else {
            $this->addError($field, "must be a valid CIDR notation");
            return false;
        }
        return true;
    }

    /**
     * Static shorthand — throws on failure.
     */
    public static function make(array $data, array $rules): array
    {
        $v = new self();
        $result = $v->validate($data, $rules);
        if ($v->fails()) {
            throw new \InvalidArgumentException(
                json_encode($v->getErrors()),
                422
            );
        }
        return $result;
    }
}
