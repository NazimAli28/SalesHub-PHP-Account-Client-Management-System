<?php

namespace Database\Seeders\Support;

/**
 * Realistic, fictional phrases for factories and seeders, so the demo UI never shows
 * placeholder Latin text.
 */
final class DemoText
{
    private const LEAD_MESSAGES = [
        'Can you do it a bit cheaper?',
        'Loved the portfolio, sending references tonight.',
        'Asked for a mascot in the same style as the samples.',
        'Wants the emotes before the weekend stream.',
        'Will confirm the budget after payday.',
        'Requested two revisions on the logo concept.',
        'Shared the brand colours and a mood board.',
        'Asked whether animated alerts are included.',
        'Comparing our quote with another studio.',
        'Ready to pay the first half today.',
    ];

    private const APPROVAL_REASONS = [
        'Client asked for an update after our last call.',
        'Correcting a typo in the client details.',
        'Follow-up date moved at the client\'s request.',
        'Client confirmed the scope on Discord.',
        'Updating the record after the payment call.',
        'Need more accounts for the evening shift.',
        'Rating adjusted after the upsell conversation.',
    ];

    private const REVIEW_COMMENTS = [
        'Checked with the client, looks good.',
        'Approved, thanks for the details.',
        'Please attach the chat screenshot next time.',
        'Not enough context, please resubmit with details.',
        'Duplicate of an earlier request.',
    ];

    private const LOST_NOTES = [
        'Went with a cheaper freelancer.',
        'Stopped replying after the quote.',
        'Postponed the rebrand until next year.',
        'Budget was cut for this season.',
    ];

    private const CLIENT_NOTES = [
        'Prefers messages after 6 pm their time.',
        'Repeat client, likes bold colours.',
        'Streams on weekends, deadlines are tight.',
        'Pays in two installments.',
        'Asked for invoices with a company name.',
    ];

    private const CLIENT_NOTE_BODIES = [
        'Called today, happy with the first draft and asked for a warmer colour palette.',
        'Prefers Discord messages over email, usually replies within a few hours.',
        'Mentioned a charity stream next month, good moment to pitch an alert pack.',
        'Asked for an invoice with the company name before paying the second half.',
        'Wants to see the animated version before approving the static one.',
        'Referred a friend who streams variety games, follow up this week.',
        'Payment will arrive after the sponsor pays them on Friday.',
        'Sent the brand guide, asked us to keep the logo font consistent.',
        'Not ready for new work until the channel hits affiliate.',
        'Very happy with the delivery, said we can use the emotes in our portfolio.',
        'Asked about a discount for bundling panels and overlays.',
        'Time zone is eight hours behind us, schedule calls in the evening.',
    ];

    private const ITEM_DESCRIPTIONS = [
        'Two revision rounds included',
        'Matches the existing brand colours',
        'Transparent PNG and source files',
        'Sizes for Twitch and Discord',
        'Rush delivery within 3 days',
    ];

    private const SERVICE_DESCRIPTIONS = [
        'Delivered as source files and web-ready exports.',
        'Includes two revision rounds.',
        'Sized for Twitch, YouTube and Discord.',
        'Hand-drawn and fully original.',
    ];

    public static function leadMessage(): string
    {
        return fake()->randomElement(self::LEAD_MESSAGES);
    }

    public static function approvalReason(): string
    {
        return fake()->randomElement(self::APPROVAL_REASONS);
    }

    public static function reviewComment(): string
    {
        return fake()->randomElement(self::REVIEW_COMMENTS);
    }

    public static function lostNote(): string
    {
        return fake()->randomElement(self::LOST_NOTES);
    }

    public static function clientNote(): string
    {
        return fake()->randomElement(self::CLIENT_NOTES);
    }

    public static function clientNoteBody(): string
    {
        return fake()->randomElement(self::CLIENT_NOTE_BODIES);
    }

    public static function itemDescription(): string
    {
        return fake()->randomElement(self::ITEM_DESCRIPTIONS);
    }

    public static function serviceDescription(): string
    {
        return fake()->randomElement(self::SERVICE_DESCRIPTIONS);
    }
}
