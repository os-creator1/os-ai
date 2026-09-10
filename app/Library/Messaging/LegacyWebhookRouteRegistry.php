<?php

namespace App\Library\Messaging;

/**
 * Legacy Provider Webhook Measurement Contract §3.6 — the single code-backed
 * source of truth for which `routes/public.php` routes S0 measures, and
 * which provider slug each one represents.
 *
 * Route name is authoritative (§3.6): the recorder looks up
 * `$request->route()?->getName()` here and nowhere else. Provider slug never
 * comes from request input — it comes only from this explicit map. A route
 * name absent from MAP is not measured; the recorder returns immediately.
 *
 * Derived mechanically from `routes/public.php` on the merged Slice 3 tree:
 * every named `inbound.*` / `dlr.*` route, minus `inbound.telnyx_managed`
 * (excluded by §3.7 default — see EXCLUDED_BY_DEFAULT). The slug for each
 * entry is the route name's own suffix, lowercased, so the map is trivially
 * auditable against the route declaration it describes.
 */
final class LegacyWebhookRouteRegistry
{
    /**
     * §3.7: the managed Telnyx route is a supported, already-known,
     * throttled and signature-verified route added by Customer Experience
     * Slice 3. This contract's default is excluded — it is not part of the
     * legacy family this slice measures. Recorded here, not in MAP, so the
     * exclusion is visible in code rather than implied by omission.
     */
    private const EXCLUDED_BY_DEFAULT = [
        'inbound.telnyx_managed' => 'telnyx-managed',
    ];

    /**
     * The twelve routes the platform's own code hands out as callback URLs
     * (SendCampaignSMS / EloquentSendingServerRepository), named in contract
     * §6 item 5. For these a zero hit count is weaker evidence of disuse
     * than it is for a hand-configured URL, because the platform actively
     * published them.
     */
    public const PLATFORM_ISSUED_CALLBACK_ROUTES = [
        'dlr.arkesel',
        'dlr.broadbased',
        'dlr.d7networks',
        'dlr.dotgo',
        'dlr.fortytwo',
        'dlr.gatewayapi',
        'dlr.infobip',
        'dlr.moceanapi',
        'dlr.smsala',
        'dlr.smsvas',
        'dlr.textlocal',
        'inbound.textbelt',
    ];

    /**
     * route name => provider slug. Every named `inbound.*` / `dlr.*` route
     * on the merged tree except `inbound.telnyx_managed` (see
     * EXCLUDED_BY_DEFAULT). A provider with both a `dlr.*` and an
     * `inbound.*` route (e.g. Twilio, GatewayAPI) shares the same slug
     * across both entries, since both routes measure the same provider's
     * usage.
     */
    private const MAP = [
        'dlr.1s2u' => '1s2u',
        'dlr.advancemsgsys' => 'advancemsgsys',
        'dlr.africastalking' => 'africastalking',
        'dlr.airtel-india' => 'airtel-india',
        'dlr.amazon-sns' => 'amazon-sns',
        'dlr.arkesel' => 'arkesel',
        'dlr.broadbased' => 'broadbased',
        'dlr.bulksms' => 'bulksms',
        'dlr.callr' => 'callr',
        'dlr.closum' => 'closum',
        'dlr.cm' => 'cm',
        'dlr.d7networks' => 'd7networks',
        'dlr.dinstar' => 'dinstar',
        'dlr.dotgo' => 'dotgo',
        'dlr.easysendsms' => 'easysendsms',
        'dlr.fortytwo' => 'fortytwo',
        'dlr.gatewayapi' => 'gatewayapi',
        'dlr.gatewaysa' => 'gatewaysa',
        'dlr.hutch' => 'hutch',
        'dlr.infobip' => 'infobip',
        'dlr.keccelsms' => 'keccelsms',
        'dlr.moceanapi' => 'moceanapi',
        'dlr.mp' => 'mp',
        'dlr.nimbuz' => 'nimbuz',
        'dlr.plivo' => 'plivo',
        'dlr.routemobile' => 'routemobile',
        'dlr.simpletexting' => 'simpletexting',
        'dlr.smsala' => 'smsala',
        'dlr.smsdenver' => 'smsdenver',
        'dlr.smsglobal' => 'smsglobal',
        'dlr.smsmode' => 'smsmode',
        'dlr.smsto' => 'smsto',
        'dlr.smsvas' => 'smsvas',
        'dlr.textlocal' => 'textlocal',
        'dlr.topying' => 'topying',
        'dlr.twilio' => 'twilio',
        'dlr.vonage' => 'vonage',
        'inbound.800com' => '800com',
        'inbound.Whatsender' => 'whatsender',
        'inbound.bandwidth' => 'bandwidth',
        'inbound.bird' => 'bird',
        'inbound.bulksms' => 'bulksms',
        'inbound.burstsms' => 'burstsms',
        'inbound.callr' => 'callr',
        'inbound.chatapi' => 'chatapi',
        'inbound.cheapglobalsms' => 'cheapglobalsms',
        'inbound.clickatell' => 'clickatell',
        'inbound.clicksend' => 'clicksend',
        'inbound.cm' => 'cm',
        'inbound.d7networks' => 'd7networks',
        'inbound.demo' => 'demo',
        'inbound.diafaan' => 'diafaan',
        'inbound.easysendsms' => 'easysendsms',
        'inbound.ejoin' => 'ejoin',
        'inbound.evolution.api' => 'evolution.api',
        'inbound.flowroute' => 'flowroute',
        'inbound.gatewayapi' => 'gatewayapi',
        'inbound.infobip' => 'infobip',
        'inbound.inteliquent' => 'inteliquent',
        'inbound.linkmobility' => 'linkmobility',
        'inbound.notifyre' => 'notifyre',
        'inbound.plivo' => 'plivo',
        'inbound.plivo_powerpack' => 'plivo_powerpack',
        'inbound.signalwire' => 'signalwire',
        'inbound.simpletexting' => 'simpletexting',
        'inbound.sinch' => 'sinch',
        'inbound.skyetel' => 'skyetel',
        'inbound.smsdenver' => 'smsdenver',
        'inbound.smsgateway' => 'smsgateway',
        'inbound.smsmode' => 'smsmode',
        'inbound.solucoesdigitais' => 'solucoesdigitais',
        'inbound.teleapi' => 'teleapi',
        'inbound.teletopiasms' => 'teletopiasms',
        'inbound.telnyx' => 'telnyx',
        'inbound.textbelt' => 'textbelt',
        'inbound.textgrid' => 'textgrid',
        'inbound.textlocal' => 'textlocal',
        'inbound.twilio' => 'twilio',
        'inbound.twilio_copilot' => 'twilio_copilot',
        'inbound.txtria' => 'txtria',
        'inbound.vonage' => 'vonage',
        'inbound.voximplant' => 'voximplant',
        'inbound.webhook' => 'webhook',
        'inbound.whatsapp' => 'whatsapp',
    ];

    /**
     * The provider slug for a measured route name, or null when the route
     * is not measured — either because it carries no name, isn't in MAP at
     * all, or is explicitly excluded by default (§3.7).
     */
    public static function slugFor(?string $routeName): ?string
    {
        if ($routeName === null || $routeName === '') {
            return null;
        }

        return self::MAP[$routeName] ?? null;
    }

    public static function isMeasured(?string $routeName): bool
    {
        return self::slugFor($routeName) !== null;
    }

    /**
     * @return array<string, string> route name => provider slug, for every
     *                                measured route.
     */
    public static function all(): array
    {
        return self::MAP;
    }

    /**
     * @return array<string, string> route name => provider slug, for every
     *                                registry entry excluded by default
     *                                (currently just the managed Telnyx
     *                                route, §3.7).
     */
    public static function excludedByDefault(): array
    {
        return self::EXCLUDED_BY_DEFAULT;
    }
}
