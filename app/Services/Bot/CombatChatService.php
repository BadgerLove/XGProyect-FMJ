<?php

declare(strict_types=1);

namespace App\Services\Bot;

use App\Models\User;

/**
 * Combat chat messages — makes bots feel alive by sending personality-driven
 * messages after attacks and defenses.
 */
class CombatChatService
{
    /**
     * Message templates by personality and event type.
     * Each personality has its own voice.
     */
    private const TEMPLATES = [
        'raider' => [
            'attack_win' => [
                'Too easy! 😈',
                'Better luck next time, rookie.',
                'Your resources are in good hands now. 💰',
                'Maybe try building some defenses? 😂',
                'Thanks for the loot! Come again soon.',
                'That was barely worth the fuel.',
                'You didn\'t even put up a fight.',
                'Consider this a learning experience. 📚',
            ],
            'attack_loss' => [
                'You got lucky this time...',
                'Enjoy it while it lasts.',
                'I\'ll be back. Count on it.',
                'You must have been online. No way you defended that fair.',
            ],
        ],
        'turtle' => [
            'attack_win' => [
                'Should have scouted first. 🛡️',
                'My defenses say hello.',
                'Wasted trip, wasn\'t it?',
                'Come back when you have a real fleet.',
                'Defense wins championships. 🏆',
            ],
            'attack_loss' => [
                'Impressive fleet. I\'ll remember that.',
                'You\'ll pay for that. My alliance will hear about this.',
                'Enjoy the resources. They cost you dearly.',
            ],
        ],
        'balanced' => [
            'attack_win' => [
                'GG',
                'Better luck next time.',
                'Nothing personal, just business.',
                'The galaxy provides. 🌌',
                'Fair fight, fair result.',
            ],
            'attack_loss' => [
                'Well played.',
                'Rematch sometime?',
                'I\'ll learn from that one.',
            ],
        ],
    ];

    /**
     * Send a chat message after a successful attack.
     *
     * @param  array<string, mixed>  $attacker  The attacking bot (user array)
     * @param  int                   $defenderId  The defender's user ID
     * @param  string                $personality  The attacker's personality
     */
    public function sendAttackWinMessage(array $attacker, int $defenderId, string $personality): void
    {
        if ($defenderId <= 0) {
            return;
        }

        $message = $this->pickMessage($personality, 'attack_win');
        if ($message === null) {
            return;
        }

        $this->send($defenderId, (int) $attacker['id'], $attacker['name'] ?? 'Unknown', $message);
    }

    /**
     * Send a chat message after bot's defense was breached.
     *
     * @param  array<string, mixed>  $defender  The defending bot (user array)
     * @param  int                   $attackerId  The attacker's user ID
     * @param  string                $personality  The defender's personality
     */
    public function sendDefenseLossMessage(array $defender, int $attackerId, string $personality): void
    {
        if ($attackerId <= 0) {
            return;
        }

        $message = $this->pickMessage($personality, 'attack_loss');
        if ($message === null) {
            return;
        }

        $this->send($attackerId, (int) $defender['id'], $defender['name'] ?? 'Unknown', $message);
    }

    /**
     * Pick a random message for the given personality and event type.
     */
    private function pickMessage(string $personality, string $event): ?string
    {
        $templates = self::TEMPLATES[$personality][$event] ?? null;

        if ($templates === null || empty($templates)) {
            return null;
        }

        return $templates[array_rand($templates)];
    }

    /**
     * Send a message via the legacy Functions::sendMessage().
     */
    private function send(int $to, int $sender, string $senderName, string $message): void
    {
        try {
            \Xgp\App\Libraries\Functions::sendMessage(
                to: $to,
                sender: $sender,
                time: 0,
                type: 0,
                from: $senderName,
                subject: '',
                message: $message,
                allowHtml: false,
            );
        } catch (\Throwable $e) {
            // Silently fail — chat messages are nice-to-have, not critical
            \Illuminate\Support\Facades\Log::debug("CombatChat: failed to send message: " . $e->getMessage());
        }
    }
}
