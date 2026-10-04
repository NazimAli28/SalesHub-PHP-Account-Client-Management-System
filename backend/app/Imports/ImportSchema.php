<?php

namespace App\Imports;

use App\Enums\ImportType;

/**
 * The importable fields of each import type, header-name suggestions and the CSV templates.
 */
final class ImportSchema
{
    /**
     * @return list<ImportField>
     */
    public static function fields(ImportType $type): array
    {
        return match ($type) {
            ImportType::Leads => [
                new ImportField('client_discord_username', 'Client Discord username', true, 'pixelpanda42', 'An existing client is reused; otherwise a new client is created.', ['discord', 'username', 'client', 'discordusername']),
                new ImportField('client_name', 'Client name', false, 'Jordan Rivera', '', ['name', 'fullname']),
                new ImportField('client_email', 'Client email', false, 'jordan.rivera@example.com', '', ['email']),
                new ImportField('stage', 'Stage', false, 'new', 'new, engaged, portfolio_shared, quoted, payment_pending or lost. Default: new.', ['status']),
                new ImportField('contacted_on', 'Contacted on', false, '2026-09-28', 'YYYY-MM-DD, not in the future. Default: today.', ['contacted', 'date', 'contactdate', 'firstcontact']),
                new ImportField('estimated_value', 'Estimated value', false, '150.00', 'Decimal amount in the lead currency.', ['value', 'amount', 'price', 'budget']),
                new ImportField('currency', 'Currency', false, 'USD', 'Three-letter code. Default: USD.'),
                new ImportField('last_message', 'Last message', false, 'Asked about a stream overlay pack.', '', ['message', 'notes']),
                new ImportField('next_follow_up_on', 'Next follow-up on', false, '2026-10-12', 'YYYY-MM-DD.', ['followup', 'followupdate', 'nextfollowup']),
                new ImportField('lost_reason', 'Lost reason', false, '', 'Required when the stage is lost: no_response, price, chose_competitor, not_ready, spam or other.'),
                new ImportField('lost_note', 'Lost note'),
                new ImportField('owner_username', 'Owner username', false, '', 'Defaults to you. Must be someone you may assign leads to.', ['owner', 'assignedto']),
            ],
            ImportType::Clients => [
                new ImportField('discord_username', 'Discord username', true, 'pixelpanda42', 'Must be unique.', ['discord', 'username', 'client']),
                new ImportField('name', 'Name', false, 'Jordan Rivera', '', ['fullname', 'clientname']),
                new ImportField('email', 'Email', false, 'jordan.rivera@example.com'),
                new ImportField('payment_name', 'Payment name', false, 'Jordan Rivera'),
                new ImportField('country', 'Country', false, 'US', 'Two-letter code.'),
                new ImportField('status', 'Status', false, 'active', 'active, nurturing, dormant or lost. Default: active.'),
                new ImportField('nurturing_rating', 'Nurturing rating', false, '60', '0 to 100.', ['rating']),
                new ImportField('next_upsell_plan', 'Next upsell plan'),
                new ImportField('expected_upsell_on', 'Expected upsell on', false, '', 'YYYY-MM-DD.'),
                new ImportField('notes', 'Notes'),
                new ImportField('owner_username', 'Owner username', false, '', 'Defaults to you.', ['owner', 'assignedto']),
            ],
        };
    }

    /**
     * @return list<string>
     */
    public static function fieldKeys(ImportType $type): array
    {
        return array_map(fn (ImportField $field) => $field->key, self::fields($type));
    }

    /**
     * @return list<string>
     */
    public static function requiredKeys(ImportType $type): array
    {
        return array_values(array_map(
            fn (ImportField $field) => $field->key,
            array_filter(self::fields($type), fn (ImportField $field) => $field->required),
        ));
    }

    public static function label(ImportType $type, string $key): string
    {
        foreach (self::fields($type) as $field) {
            if ($field->key === $key) {
                return $field->label;
            }
        }

        return $key;
    }

    /**
     * Guesses header => field by normalised name or alias. Each field is used at most once.
     *
     * @param  list<string>  $headers
     * @return array<string, string|null>
     */
    public static function suggestMapping(ImportType $type, array $headers): array
    {
        $suggested = [];
        $used = [];

        foreach ($headers as $header) {
            $normalised = self::normalise($header);
            $suggested[$header] = null;

            foreach (self::fields($type) as $field) {
                if (isset($used[$field->key])) {
                    continue;
                }
                $names = [
                    self::normalise($field->key),
                    self::normalise($field->label),
                    ...array_map(self::normalise(...), $field->aliases),
                ];
                if (in_array($normalised, $names, true)) {
                    $suggested[$header] = $field->key;
                    $used[$field->key] = true;

                    break;
                }
            }
        }

        return $suggested;
    }

    public static function normalise(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($value)) ?? '';
    }

    /**
     * CSV template: the field keys as headers and one fictional example row.
     */
    public static function template(ImportType $type): string
    {
        $fields = self::fields($type);
        $out = fopen('php://temp', 'r+');
        fputcsv($out, array_map(fn (ImportField $f) => $f->key, $fields), ',', '"', '');
        fputcsv($out, array_map(fn (ImportField $f) => $f->example, $fields), ',', '"', '');
        rewind($out);

        return (string) stream_get_contents($out);
    }
}
