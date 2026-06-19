<?php
/**
 * Router — update turini aniqlab mos handlerga yo'naltiradi.
 */
final class Router
{
    public static function dispatch(object $update): void
    {
        if (isset($update->inline_query)) {
            InlineHandler::handle($update->inline_query);
            return;
        }
        if (isset($update->callback_query)) {
            CallbackHandler::handle($update->callback_query);
            return;
        }
        if (isset($update->message)) {
            self::message($update->message);
            return;
        }
        // channel_post, edited_message va h.k. — e'tiborsiz
    }

    private static function message(object $m): void
    {
        $cid = (int)($m->chat->id ?? 0);
        if ($cid === 0) return;

        $from = $m->from ?? null;

        $ctx = [
            'cid'      => $cid,
            'mid'      => (int)($m->message_id ?? 0),
            'text'     => (string)($m->text ?? ($m->caption ?? '')),
            'video'    => $m->video ?? null,
            'document' => $m->document ?? null,
            'photo'    => $m->photo ?? null,
            'from'     => $from,
            'name'     => $from ? fullName($from) : '',
            'username' => $from->username ?? '',
            'step'     => '',
        ];

        // Foydalanuvchini ro'yxatga olish / yangilash
        UserRepo::touch($cid, $ctx['name'], $ctx['username']);

        // Blok
        if (UserRepo::isBlocked($cid) && !AdminRepo::isAdmin($cid)) {
            Telegram::send($cid, "⛔ Siz botdan bloklangansiz.");
            return;
        }

        // Web App'dan kelgan ma'lumot (Telegram.WebApp.sendData) — alohida yo'l
        if (isset($m->web_app_data)) {
            WebAppHandler::handleData($cid, (string)($m->web_app_data->data ?? ''));
            return;
        }

        $text = trim($ctx['text']);

        // AI rejimi (faqat AI session aktiv / "🤖 AI Yordamchi" tugmasi uchun).
        // intercept() false qaytarsa — eski oqim hech o'zgarmasdan davom etadi.
        if (AiHandler::intercept($ctx)) {
            return;
        }

        // "Adminga xabar" rejimi (faqat shu rejim faol / "📨 Adminga xabar" tugmasi uchun).
        if (ContactHandler::intercept($ctx)) {
            return;
        }

        // Global bekor qilish
        if ($text === '/bekor' || $text === '/cancel') {
            State::clear($cid);
            showMenu($cid, "❌ Amal bekor qilindi.");
            return;
        }

        // /start
        if (str_starts_with($text, '/start')) {
            StartHandler::handle($ctx);
            return;
        }

        // Step timeout (eskirgan holatni avtomatik bekor qilish)
        if (State::isExpired($cid)) {
            State::clear($cid);
            showMenu($cid, "⏰ Vaqt tugadi — amal bekor qilindi. Qaytadan boshlang.");
        }
        $ctx['step'] = State::step($cid);

        // Admin handlerlari (iste'mol qilsa — to'xtaymiz)
        if (AdminRepo::isAdmin($cid) && AdminHandler::handle($ctx)) {
            return;
        }

        // Oddiy foydalanuvchi funksiyalari
        MessageHandler::handle($ctx);
    }
}
