-- GhostFrog eBay / Python operational PostgreSQL schema, exported 2026-11-22.
-- Restore into an empty PostgreSQL 16 database with pg_trgm available.
-- Contains schema definitions only. Database roles and grants are environment-specific.

--
-- PostgreSQL database dump
--

\restrict KeYlTagP9NF5TEVTQb2vnQibOhIkbdoqOLixFQA8bphJJbsw4eH3UwzhrEIgtdY

-- Dumped from database version 16.13 (Homebrew)
-- Dumped by pg_dump version 16.13 (Homebrew)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: pg_trgm; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS pg_trgm WITH SCHEMA public;


--
-- Name: EXTENSION pg_trgm; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION pg_trgm IS 'text similarity measurement and index searching based on trigrams';


--
-- Name: ghostfrog_apple_evidence(text, jsonb); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.ghostfrog_apple_evidence(title_text text, item_specifics jsonb) RETURNS TABLE(evidence_seq bigint, field text, value_index bigint, part_index integer, part_count integer, product_value text)
    LANGUAGE sql IMMUTABLE
    SET search_path TO 'pg_catalog'
    AS $$
    WITH RECURSIVE values_at_path(field, path, value) AS (
        SELECT 'title'::text, ARRAY[]::bigint[], to_jsonb(title_text)
        UNION ALL
        SELECT j.key, ARRAY[]::bigint[], j.value
        FROM jsonb_each(CASE WHEN jsonb_typeof(item_specifics) = 'object'
                            THEN item_specifics ELSE '{}'::jsonb END) AS j
        WHERE (CASE WHEN lower(j.key) LIKE 'browse aspect %' THEN lower(substr(j.key, 15))
                    ELSE lower(j.key) END) IN (
            'brand', 'model', 'model name', 'model number', 'model identifier', 'apple model',
            'mpn', 'manufacturer part number', 'product line', 'series', 'type', 'variant',
            'generation', 'release year', 'year of manufacture', 'screen size', 'display',
            'display type', 'maximum resolution', 'processor', 'processor model', 'processor speed',
            'chip', 'chipset model', 'cpu', 'gpu', 'graphics processing type', 'ram', 'ram size',
            'memory', 'memory size', 'unified memory', 'storage capacity', 'ssd capacity',
            'hard drive capacity', 'storage type', 'capacity', 'connectivity', 'internet connectivity',
            'network connectivity', 'network', 'carrier', 'cellular band', 'sim card slot',
            'lock status', 'activation lock', 'icloud lock', 'carrier lock', 'network lock',
            'condition', 'browse condition', 'browse conditiondescription', 'condition description',
            'conditiondescription', 'item condition', 'cosmetic condition', 'condition/grade',
            'grade', 'defect', 'defects', 'defect description', 'damage', 'fault', 'faults', 'parts',
            'parts or repair', 'repair',
            'features', 'battery health', 'battery capacity', 'case size', 'case material', 'material',
            'colour', 'color', 'manufacturer colour', 'unit quantity', 'pack quantity',
            'number in pack', 'number of items in set'
        )
        UNION ALL
        SELECT parent.field, parent.path || child.ordinal, child.value
        FROM values_at_path AS parent
        CROSS JOIN LATERAL (
            SELECT entry.value, entry.ordinal
            FROM jsonb_array_elements(CASE WHEN jsonb_typeof(parent.value) = 'array'
                                          THEN parent.value ELSE '[]'::jsonb END)
                 WITH ORDINALITY AS entry(value, ordinal)
            UNION ALL
            SELECT parent.value -> wrapper.key, wrapper.ordinal
            FROM (VALUES ('value', 1::bigint), ('values', 2::bigint)) AS wrapper(key, ordinal)
            WHERE jsonb_typeof(parent.value) = 'object' AND parent.value ? wrapper.key
              AND NOT EXISTS (
                  SELECT 1
                  FROM (VALUES ('name'), ('key'), ('label')) AS descriptor(key)
                  WHERE parent.value ? descriptor.key
                    AND lower(parent.field) IS DISTINCT FROM lower(parent.value ->> descriptor.key)
                    AND lower(parent.field) IS DISTINCT FROM
                        ('browse aspect ' || lower(parent.value ->> descriptor.key))
              )
        ) AS child
    ),
    sanitized AS MATERIALIZED (
        SELECT entry.field, entry.path, public.ghostfrog_product_text(entry.value #>> '{}') AS value
        FROM values_at_path AS entry
        WHERE jsonb_typeof(entry.value) IN ('string', 'number', 'boolean')
    ),
    numbered AS (
        SELECT item.field, item.value, row_number() OVER (
                   PARTITION BY item.field ORDER BY item.path
               ) AS value_index, (length(item.value) + 479) / 480 AS part_count
        FROM sanitized AS item
        WHERE item.value <> ''
    )
SELECT row_number() OVER (ORDER BY item.field <> 'title', item.field COLLATE "C", item.value_index, part.n),
    item.field, item.value_index, part.n, item.part_count,
       substr(item.value, (part.n - 1) * 480 + 1, 480)
FROM numbered AS item
         CROSS JOIN LATERAL generate_series(1, item.part_count) AS part(n)
$$;


--
-- Name: ghostfrog_listing_comparables(integer, integer, integer); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.ghostfrog_listing_comparables(target_id integer, page_offset integer, page_limit integer) RETURNS TABLE(listing_id integer, item_id text, title text, url text, model_key text, condition_grade text, final_price numeric, end_time timestamp with time zone, text_review_required boolean, visual_review_required boolean)
    LANGUAGE sql STABLE SECURITY DEFINER
    SET search_path TO 'pg_catalog'
    AS $_$
    WITH target AS MATERIALIZED (
        SELECT t.listing_id, t.item_id, t.source, t.model_key, t.effective_condition_grade
        FROM public.listing_inspection_v AS t
        WHERE t.listing_id = target_id
          AND t.model_key ~ '^[a-z0-9][a-z0-9.-]+$'
          AND t.model_key !~ '(^unknown|generic|gen-unk)'
          AND t.effective_condition_grade IN ('A', 'B', 'C')
    )
SELECT a.id, identifier.item_id, public.ghostfrog_product_text(a.title),
       public.ghostfrog_marketplace_url(a.url, false), a.model_key,
       grade.effective, a.final_price, a.end_time, tc.review_required, vc.review_required
FROM target AS t
         JOIN public.auction_listings AS a ON a.model_key = t.model_key AND a.source = t.source
         CROSS JOIN LATERAL (
    SELECT CASE WHEN a.external_id LIKE 'v1|%' THEN split_part(a.external_id, '|', 2)
                ELSE a.external_id END AS item_id
        ) AS identifier
         LEFT JOIN LATERAL (
    SELECT c.text_grade, c.review_required FROM public.listing_text_conditions AS c
    WHERE c.listing_id = a.id ORDER BY c.checked_at DESC, c.id DESC LIMIT 1
    ) AS tc ON true
    LEFT JOIN LATERAL (
    SELECT c.visual_grade, c.review_required FROM public.listing_visual_conditions AS c
    WHERE c.listing_id = a.id ORDER BY c.checked_at DESC, c.id DESC LIMIT 1
    ) AS vc ON true
    CROSS JOIN LATERAL (
    SELECT CASE
    WHEN 'D' IN (upper(COALESCE(a.condition_grade, 'B')), upper(tc.text_grade), upper(vc.visual_grade)) THEN 'D'
    WHEN 'C' IN (upper(COALESCE(a.condition_grade, 'B')), upper(tc.text_grade), upper(vc.visual_grade)) THEN 'C'
    WHEN 'B' IN (upper(COALESCE(a.condition_grade, 'B')), upper(tc.text_grade), upper(vc.visual_grade)) THEN 'B'
    ELSE 'A' END AS effective
    ) AS grade
WHERE a.status = 'sold' AND a.finalized IS TRUE AND a.final_price > 0
  AND a.end_time BETWEEN now() - interval '365 days' AND now()
  AND a.external_id ~ '^([0-9]{12}|v1[|][0-9]{12}[|][0-9]{1,12})$'
  AND grade.effective = t.effective_condition_grade AND identifier.item_id <> t.item_id
  AND NOT COALESCE(tc.review_required, false) AND NOT COALESCE(vc.review_required, false)
  AND NOT public.ghostfrog_listing_identity_risk(a.title, a.raw_attrs)
  AND COALESCE((SELECT r.action FROM public.listing_review_actions AS r
    WHERE r.external_id = a.external_id ORDER BY r.created_at DESC, r.id DESC LIMIT 1), '') <> 'down'
ORDER BY a.end_time DESC, a.id DESC
    LIMIT LEAST(GREATEST(COALESCE(page_limit, 0), 0), 51)
OFFSET LEAST(GREATEST(COALESCE(page_offset, 0), 0), 10000)
    $_$;


--
-- Name: ghostfrog_listing_evidence(text, text, jsonb, jsonb); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.ghostfrog_listing_evidence(title_text text, notes_text text, attrs jsonb, assessments jsonb) RETURNS TABLE(section text, evidence_seq bigint, field text, value_path jsonb, part_index integer, part_count integer, value jsonb, withheld boolean)
    LANGUAGE sql IMMUTABLE
    SET search_path TO 'pg_catalog'
    AS $_$
    WITH RECURSIVE
    original AS (
        SELECT j.key, j.value,
               CASE WHEN lower(j.key) LIKE 'browse aspect %' THEN lower(substr(j.key, 15))
                    ELSE lower(j.key) END AS label
        FROM jsonb_each(CASE WHEN jsonb_typeof(attrs) = 'object' THEN attrs ELSE '{}'::jsonb END) AS j
    ),
    classified AS (
        SELECT item.key, item.value, CASE
            WHEN item.label IN ('browse imageurl', 'browse imageurls') THEN 'photos'
            WHEN item.label IN (
                'browse seller username', 'browse seller feedbackscore', 'browse seller feedbackpercentage',
                'browse shippingoptions', 'browse returnterms returnsaccepted', 'browse returnterms refundmethod',
                'browse returnterms returnmethod', 'browse returnterms returnperiod',
                'browse returnterms returnshippingcostpayer', 'browse itemlocation country',
                'browse itemlocation city', 'browse itemlocation stateorprovince', 'browse condition',
                'browse buyingoptions', 'browse estimatedavailabilities', 'browse availability',
                'browse itemcreationdate', 'browse itemenddate'
            ) THEN 'seller_terms'
            WHEN item.label IN (
                'description', 'short description', 'shortdescription', 'subtitle', 'conditiondescription',
                'condition description', 'browse description', 'browse shortdescription',
                'browse subtitle', 'browse conditiondescription'
            ) THEN 'description'
            WHEN public.ghostfrog_listing_product_field(item.label, lower(item.key) LIKE 'browse aspect %')            THEN 'product'

        END AS section
        FROM original AS item
    ),
    tree(section, field, path, position, input) AS (
        SELECT item.section, item.key, jsonb_build_array(item.key), ARRAY[]::bigint[], item.value
        FROM classified AS item WHERE item.section IS NOT NULL
        UNION ALL
        SELECT 'description', text.key, jsonb_build_array(text.key), ARRAY[]::bigint[], to_jsonb(text.value)
        FROM (VALUES ('title', title_text), ('notes', notes_text)) AS text(key, value)
        WHERE text.value IS NOT NULL
        UNION ALL
        SELECT 'checks', check_entry.key, jsonb_build_array(check_entry.key), ARRAY[]::bigint[], check_entry.value
        FROM jsonb_each(COALESCE(assessments, '{}'::jsonb)) AS check_entry
        UNION ALL
        SELECT parent.section, parent.field, parent.path || child.label, parent.position || child.ordinal, child.value
        FROM tree AS parent
        CROSS JOIN LATERAL (
            SELECT to_jsonb(a.ordinal - 1) AS label, a.ordinal, a.value
            FROM jsonb_array_elements(CASE WHEN jsonb_typeof(parent.input) = 'array'
                                          THEN parent.input ELSE '[]'::jsonb END)
                 WITH ORDINALITY AS a(value, ordinal)
            UNION ALL
            SELECT to_jsonb(j.key), row_number() OVER (ORDER BY j.key COLLATE "C"), j.value
            FROM jsonb_each(CASE WHEN jsonb_typeof(parent.input) = 'object'
                                 THEN parent.input ELSE '{}'::jsonb END) AS j
            WHERE (
                (parent.section = 'photos' AND j.key IN ('imageUrl', 'url'))
                OR (parent.section = 'seller_terms' AND parent.field IN
                    ('Browse shippingOptions', 'Browse estimatedAvailabilities')
                    AND j.key IN ('shippingServiceCode', 'shippingCarrierCode', 'type', 'shippingCost',
                                  'value', 'currency', 'minEstimatedDeliveryDate', 'maxEstimatedDeliveryDate',
                                  'estimatedAvailabilityStatus', 'estimatedAvailableQuantity', 'availabilityThreshold'))
                OR (parent.section = 'checks' AND j.key IN
                    ('notes', 'risk_reasons', 'facts', 'condition_normalized', 'price_analysis',
                     'condition', 'missing_parts', 'repair', 'lock_status', 'image_count', 'elapsed_s',
                     'shipping', 'fees', 'estimated_resale', 'min_profit', 'expected_profit', 'value', 'values'))
                OR (parent.section = 'product' AND public.ghostfrog_listing_product_field(lower(j.key), false))
                OR (parent.section IN ('product', 'description') AND j.key IN ('value', 'values') AND NOT EXISTS (
                    SELECT 1
                    FROM (VALUES ('name'), ('key'), ('label')) AS descriptor(key)
                    WHERE parent.input ? descriptor.key
                      AND lower(parent.field) IS DISTINCT FROM lower(parent.input ->> descriptor.key)
                      AND lower(parent.field) IS DISTINCT FROM ('browse aspect ' || lower(parent.input ->> descriptor.key))
                ))
            )
        ) AS child
    ),
    safe AS MATERIALIZED (
        SELECT t.section, t.field, t.path, t.position, jsonb_typeof(t.input) AS kind,
               CASE
                   WHEN t.section = 'photos' THEN public.ghostfrog_marketplace_url(t.input #>> '{}', true)
                   WHEN lower(t.field) = 'browse seller username' THEN
                       CASE WHEN t.input #>> '{}' ~ '^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$'
                            AND public.ghostfrog_product_text(t.input #>> '{}') = t.input #>> '{}'
                            THEN t.input #>> '{}' END
                   WHEN lower(t.field) LIKE 'browse itemlocation %' THEN
                       CASE WHEN t.input #>> '{}' ~ '^[[:alpha:]][[:alpha:] .''-]{0,63}$'
                            THEN public.ghostfrog_product_text(t.input #>> '{}') END
                   WHEN jsonb_typeof(t.input) = 'string' THEN public.ghostfrog_product_text(t.input #>> '{}')
                   WHEN t.section = 'product' AND t.input #>> '{}' ~ '^[0-9]{9,}$' THEN NULL
                   ELSE t.input #>> '{}'
               END AS text_value
        FROM tree AS t
        WHERE jsonb_typeof(t.input) IN ('string', 'number', 'boolean')
    ),
    numbered AS (
        SELECT s.*, GREATEST(1, (COALESCE(length(s.text_value), 0) + 479) / 480) AS parts
        FROM safe AS s
        WHERE s.text_value IS DISTINCT FROM ''
    )
SELECT n.section,
       row_number() OVER (PARTITION BY n.section ORDER BY n.field <> 'title', n.field COLLATE "C", n.position, p.part),
    n.field, n.path, p.part, n.parts,
       CASE WHEN n.kind IN ('number', 'boolean') AND n.section <> 'photos' THEN n.text_value::jsonb
            ELSE to_jsonb(substr(n.text_value, (p.part - 1) * 480 + 1, 480)) END,
       n.text_value IS NULL
FROM numbered AS n CROSS JOIN LATERAL generate_series(1, n.parts) AS p(part)
$_$;


--
-- Name: ghostfrog_listing_identity_risk(text, jsonb); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.ghostfrog_listing_identity_risk(title_text text, attrs jsonb) RETURNS boolean
    LANGUAGE sql IMMUTABLE
    SET search_path TO 'pg_catalog'
    AS $_$
SELECT (coalesce(title_text, '') || ' ' || coalesce(attrs::text, '')) ~* $re$\m(for parts|parts only|for spares|spares only|spares or repair|parts or repair|repair only|needs repair|faulty|not working|untested|water damage|activation locked|icloud locked|mdm locked|locked to owner|locked|empty box|box only|case only|replacement screen)\M$re$
$_$;


--
-- Name: ghostfrog_listing_product_field(text, boolean); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.ghostfrog_listing_product_field(label text, from_browse boolean) RETURNS boolean
    LANGUAGE sql IMMUTABLE STRICT
    SET search_path TO 'pg_catalog'
    AS $_$
SELECT label IN ('model name', 'device name') OR (
    label ~ '^[a-z0-9][a-z0-9 (),/.+_-]{0,79}$'
             AND label !~ '(^|[^a-z0-9])(seller|buyer|recipient|account|address|postcode|zip|contact|e.?mail|telephone|phone|serial|imei|iccid|eid|uid|epid|url|token|key|password|secret|signature|auth|cookie|tracking|username|name|location|latitude|longitude|payment|payload|credential|metadata)([^a-z0-9]|$)'
             AND label !~ '^(browse |raw |misc|source|_|custom)'
             AND (from_browse OR label ~
                  '(^|[^a-z0-9])(brand|manufacturer|model|mpn|part|number|type|variant|series|generation|product|hardware|chip|processor|cpu|gpu|graphics|core|memory|ram|ssd|hdd|drive|storage|capacity|display|screen|resolution|colour|color|finish|dimension|size|weight|year|operating|firmware|software|connector|connectivity|network|carrier|band|sim|lock|activation|mdm|icloud|features|condition|grade|wear|battery|repair|fault|defect|damage|parts|bundle|pack|quantity|included|case|material|compatible|power|input|output|interface|speed|voltage|wattage|warranty|edition|language|audio|video|camera|lens|format|region|platform|game|set|pieces|ports|socket|mount|aspect|charging|release|gender|age|character|theme)([^a-z0-9]|$)')
    )
           $_$;


--
-- Name: ghostfrog_marketplace_url(text, boolean); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.ghostfrog_marketplace_url(input text, image boolean) RETURNS text
    LANGUAGE sql IMMUTABLE STRICT
    SET search_path TO 'pg_catalog'
    AS $_$
    WITH parsed AS (
        SELECT split_part(split_part(input, '?', 1), '#', 1) AS path
    )
SELECT CASE
           WHEN path ~* '(sk[-_][a-z0-9_-]{12,}|gh[pousr]_[a-z0-9_]{12,}|github_pat_|xox[baprs]-|AKIA[a-z0-9]{16}|eyJ[a-z0-9_-]+[.][a-z0-9_-]+[.][a-z0-9_-]+)'
           OR path ~ '/[.][.]?(/|$)' THEN NULL
        WHEN image AND path ~ '^https?://i[.]ebayimg[.]com/(?:images/g/|thumbs/images/g/)[A-Za-z0-9_~./-]+$'
        THEN path
        WHEN NOT image AND path
             ~ '^https?://(?:www[.])?ebay[.](?:co[.]uk|com|com[.]au|ca|de|fr|it|es|ie|nl|at|ch|be|pl)/itm/(?:[A-Za-z0-9_.~-]+/)?[0-9]{12}/?$'
        THEN path || COALESCE('?var=' || substring(input FROM '[?&]var=([0-9]{1,12})(?:[&#]|$)'), '')
END
FROM parsed
$_$;


--
-- Name: ghostfrog_product_text(text); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.ghostfrog_product_text(input text) RETURNS text
    LANGUAGE plpgsql IMMUTABLE STRICT
    SET search_path TO 'pg_catalog'
    AS $_$
DECLARE
value text := translate(input, '＠．', '@.');
    pattern text;
BEGIN
value := regexp_replace(value, '(&#0*64;|&#x0*40;|&commat;|%40|\[[[:space:]]*at[[:space:]]*\]|\([[:space:]]*at[[:space:]]*\))', '@', 'gi');
value := regexp_replace(value, '(&#0*46;|&#x0*2e;|&period;|%2e|\[[[:space:]]*dot[[:space:]]*\]|\([[:space:]]*dot[[:space:]]*\))', '.', 'gi');
value := regexp_replace(value, $re$-----BEGIN ([A-Z0-9 ]+ )?PRIVATE KEY-----.*?(-----END ([A-Z0-9 ]+ )?PRIVATE KEY-----|$)$re$, '[redacted]', 'gi');
    value := regexp_replace(value, '<[^>]+>', ' ', 'g');

    FOREACH pattern IN ARRAY ARRAY[
        $re$(?:https?://|www\.)[^[:space:]<>|;]+$re$,
$re$[[:alnum:]_.%+\-]+[[:blank:]]*@[[:blank:]]*[[:alnum:]\-]+(?:[[:blank:]]*\.[[:blank:]]*[[:alnum:]\-]+)+$re$,
        $re$(?<![[:alnum:]])@[A-Za-z][A-Za-z0-9_.-]{2,}\M$re$,
        $re$\m(?:authorization|proxy-authorization|cookie|set-cookie|x-api-key)\M[[:blank:]]*[:=][^|\r\n]*$re$,
        $re$\m(?:api[ _-]?key|access[ _-]?token|refresh[ _-]?token|session[ _-]?token|client[ _-]?secret|password|passwd|secret|token)\M[[:blank:]]*[:=][^|,;\r\n]*$re$,
        $re$\m(?:bearer|basic)[[:blank:]]+[A-Za-z0-9._+/=\-]{8,}$re$,
        $re$\m(?:sk[-_][A-Za-z0-9_-]{12,}|gh[pousr]_[A-Za-z0-9_]{12,}|github_pat_[A-Za-z0-9_]{12,}|xox[baprs]-[A-Za-z0-9-]{10,}|(?:AKIA|ASIA)[A-Z0-9]{16}|eyJ[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+)\M$re$,
        $re$\m(?=[A-Za-z0-9_-]{24,}\M)(?=[A-Za-z0-9_-]*[0-9])[A-Za-z0-9_-]+\M$re$,
        $re$\m(?:(?:seller|buyer|owner|contact|recipient)[ _-]+(?:name|username|user[ _-]?id|email|phone|address|details)|seller|buyer|owner|contact|recipient|username|e-?mail|mobile|telephone|phone|whatsapp|telegram|shipping[ _-]+address|return[ _-]+address|postal[ _-]+address|address|postcode|zip[ _-]+code|bank[ _-]+details|iban|sort[ _-]+code|account[ _-]+number)\M[[:blank:]]*[:=][^|;\r\n]*$re$,
        $re$\m(?:collect(?:ion)?[[:blank:]]+(?:from|at|in)|meet[[:blank:]]+(?:me[[:blank:]]+)?at|seller[[:blank:]]+is|sold[[:blank:]]+by|(?:call|text|message|contact)[[:blank:]]+(?:me|us)[[:blank:]]+(?:at|on))\M[[:blank:]]+[^|;\r\n]*$re$,
        $re$\m(?:serial(?:[[:blank:]]+(?:number|no\.?))?|IMEI(?:[[:blank:]]+[12])?|ICCID|EID|UDID)\M[[:blank:]]*(?:[:=#-][[:blank:]]*|[[:blank:]]+is[[:blank:]]+|[[:blank:]]+)[A-Za-z0-9-]{6,}\M$re$,
        $re$\m(?:\d{1,5}[A-Z]?[[:blank:]]+(?:[[:alnum:].'’-]+[[:blank:]]+){1,5}(?:street|st|road|rd|avenue|ave|lane|ln|drive|dr|close|way|court|ct|terrace|place|pl|boulevard|blvd|crescent|square|gardens|grove))\M\.?$re$,
        $re$\m(?!(?:M[1-9]|A[0-9]{1,2})[[:blank:]]+[0-9](?:GB|TB|MB)\M)(?:GIR[[:blank:]]*0AA|[A-Z]{1,2}[0-9][0-9A-Z]?[[:blank:]]+[0-9][A-Z]{2})\M$re$,
        $re$(?<![[:alnum:]])(?:\+?[0-9]|\([0-9])[0-9(). -]{7,}[0-9](?![[:alnum:]])$re$,
        $re$\m(?:[0-9]{1,3}\.){3}[0-9]{1,3}\M$re$
    ] LOOP
        value := regexp_replace(value, pattern, '[redacted]', 'gi');
    END LOOP;

    RETURN btrim(regexp_replace(value, '[[:cntrl:]]+', ' ', 'g'));
END
$_$;


SET default_table_access_method = heap;

--
-- Name: alert_state; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.alert_state (
    name text NOT NULL,
    last_sent_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: alerts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.alerts (
    id integer NOT NULL,
    listing_external_id text,
    snipe_score numeric,
    max_bid numeric,
    sent_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    outcome text,
    external_id text,
    roi_net numeric,
    reason text,
    model_key text,
    score real DEFAULT 0,
    created_at timestamp with time zone DEFAULT (now() AT TIME ZONE 'utc'::text),
    updated_at timestamp with time zone DEFAULT (now() AT TIME ZONE 'utc'::text)
);


--
-- Name: alerts_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.alerts_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: alerts_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.alerts_id_seq OWNED BY public.alerts.id;


--
-- Name: api_usage; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.api_usage (
    id integer NOT NULL,
    service text NOT NULL,
    call_count integer DEFAULT 0 NOT NULL,
    date date DEFAULT CURRENT_DATE NOT NULL,
    created_at timestamp with time zone DEFAULT now(),
    updated_at timestamp with time zone DEFAULT now()
);


--
-- Name: api_usage_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.api_usage_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: api_usage_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.api_usage_id_seq OWNED BY public.api_usage.id;


--
-- Name: auction_listings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.auction_listings (
    id integer NOT NULL,
    source text NOT NULL,
    external_id text NOT NULL,
    title text,
    price_current bigint,
    bids_count integer,
    end_time timestamp with time zone,
    status text DEFAULT 'live'::text,
    url text,
    first_seen timestamp with time zone DEFAULT CURRENT_TIMESTAMP,
    fetched_at timestamp with time zone,
    roi_estimate double precision,
    max_bid bigint,
    notes text,
    source_id integer,
    final_price numeric(12,2),
    sale_type text,
    model_key text,
    time_left_s integer,
    finalized boolean DEFAULT false,
    price_bid_current integer,
    brand text,
    product_family text,
    model_name text,
    storage_gb integer,
    colour text,
    epid text,
    raw_attrs jsonb,
    last_seen_at timestamp with time zone,
    bucket_key text,
    backfill_checked_at timestamp with time zone,
    backfill_check_count integer DEFAULT 0 NOT NULL,
    backfill_last_reason text,
    condition_grade text,
    visual_condition_checked boolean DEFAULT false NOT NULL,
    visual_condition_checked_at timestamp without time zone,
    text_condition_checked boolean DEFAULT false NOT NULL,
    text_condition_checked_at timestamp without time zone,
    CONSTRAINT auction_listings_status_not_null CHECK ((status IS NOT NULL))
);


--
-- Name: apple_listing_evidence_v; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.apple_listing_evidence_v WITH (security_barrier='true') AS
 SELECT listing.id AS listing_id,
    listing.external_id,
    listing.source,
    listing.status,
    listing.model_key,
    listing.condition_grade,
    listing.final_price,
    listing.end_time,
    listing.last_seen_at,
    listing.fetched_at,
    evidence.evidence_seq,
    evidence.field,
    evidence.value_index,
    evidence.part_index,
    evidence.part_count,
    evidence.product_value
   FROM (public.auction_listings listing
     CROSS JOIN LATERAL public.ghostfrog_apple_evidence(listing.title, listing.raw_attrs) evidence(evidence_seq, field, value_index, part_index, part_count, product_value))
  WHERE ((listing.source = 'ebay-apple'::text) AND (listing.external_id ~ '^([0-9]{12}|v1[|][0-9]{12}[|][0-9]{1,12})$'::text));


--
-- Name: articles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.articles (
    id integer NOT NULL,
    source_id integer NOT NULL,
    title text NOT NULL,
    url text NOT NULL,
    summary text,
    published_at timestamp without time zone,
    fetched_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    content text,
    sentiment text,
    created_at_utc timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at_utc timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: articles_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.articles_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: articles_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.articles_id_seq OWNED BY public.articles.id;


--
-- Name: auction_listings_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.auction_listings_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: auction_listings_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.auction_listings_id_seq OWNED BY public.auction_listings.id;


--
-- Name: auction_price_history; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.auction_price_history (
    id integer NOT NULL,
    price bigint,
    bids_count integer,
    "timestamp" timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    external_id text,
    recorded_at timestamp with time zone DEFAULT (now() AT TIME ZONE 'utc'::text) NOT NULL
);


--
-- Name: auction_price_history_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.auction_price_history_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: auction_price_history_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.auction_price_history_id_seq OWNED BY public.auction_price_history.id;


--
-- Name: auction_price_history_orphans; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.auction_price_history_orphans (
    original_id integer NOT NULL,
    price bigint,
    bids_count integer,
    "timestamp" timestamp without time zone,
    external_id text,
    recorded_at timestamp with time zone NOT NULL,
    archived_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    archive_reason text NOT NULL
);


--
-- Name: comps; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.comps (
    model_key text NOT NULL,
    median_final_price numeric,
    mean_final_price numeric,
    samples integer,
    computed_at timestamp with time zone,
    condition_grade text DEFAULT 'B'::text NOT NULL
);


--
-- Name: ebay_app_token; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ebay_app_token (
    id integer DEFAULT 1 NOT NULL,
    token text NOT NULL,
    expiry_ts timestamp with time zone NOT NULL,
    updated_at timestamp with time zone DEFAULT (now() AT TIME ZONE 'utc'::text) NOT NULL
);


--
-- Name: latest_comps; Type: MATERIALIZED VIEW; Schema: public; Owner: -
--

CREATE MATERIALIZED VIEW public.latest_comps AS
 SELECT DISTINCT ON (model_key, condition_grade) model_key,
    condition_grade,
    median_final_price,
    mean_final_price,
    samples,
    computed_at
   FROM public.comps
  ORDER BY model_key, condition_grade, computed_at DESC
  WITH NO DATA;


--
-- Name: listing_text_conditions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.listing_text_conditions (
    id bigint NOT NULL,
    listing_id bigint NOT NULL,
    external_id text NOT NULL,
    model_name text NOT NULL,
    assessment jsonb NOT NULL,
    text_grade text,
    confidence numeric,
    risk_flags text[] DEFAULT '{}'::text[] NOT NULL,
    review_required boolean DEFAULT true NOT NULL,
    checked_at timestamp without time zone DEFAULT (now() AT TIME ZONE 'utc'::text) NOT NULL
);


--
-- Name: latest_listing_text_conditions; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.latest_listing_text_conditions AS
 SELECT DISTINCT ON (listing_id) id,
    listing_id,
    external_id,
    model_name,
    assessment,
    text_grade,
    confidence,
    risk_flags,
    review_required,
    checked_at
   FROM public.listing_text_conditions
  ORDER BY listing_id, checked_at DESC, id DESC;


--
-- Name: listing_visual_conditions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.listing_visual_conditions (
    id bigint NOT NULL,
    listing_id bigint NOT NULL,
    external_id text NOT NULL,
    model_name text NOT NULL,
    image_urls jsonb DEFAULT '[]'::jsonb NOT NULL,
    assessment jsonb NOT NULL,
    visual_grade text,
    confidence numeric,
    damage_flags text[] DEFAULT '{}'::text[] NOT NULL,
    review_required boolean DEFAULT true NOT NULL,
    checked_at timestamp without time zone DEFAULT (now() AT TIME ZONE 'utc'::text) NOT NULL
);


--
-- Name: latest_listing_visual_conditions; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.latest_listing_visual_conditions AS
 SELECT DISTINCT ON (listing_id) id,
    listing_id,
    external_id,
    model_name,
    image_urls,
    assessment,
    visual_grade,
    confidence,
    damage_flags,
    review_required,
    checked_at
   FROM public.listing_visual_conditions
  ORDER BY listing_id, checked_at DESC, id DESC;


--
-- Name: listing_assessments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.listing_assessments (
    id bigint NOT NULL,
    listing_id bigint NOT NULL,
    assessment jsonb NOT NULL,
    created_at timestamp with time zone DEFAULT (now() AT TIME ZONE 'utc'::text) NOT NULL,
    verdict text,
    confidence double precision,
    recommended_max_bid numeric
);


--
-- Name: listing_assessments_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.listing_assessments_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: listing_assessments_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.listing_assessments_id_seq OWNED BY public.listing_assessments.id;


--
-- Name: sources; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sources (
    id integer NOT NULL,
    name text NOT NULL,
    type text DEFAULT 'website'::text NOT NULL,
    base_url text NOT NULL,
    enabled boolean DEFAULT true,
    created_at_utc timestamp without time zone NOT NULL,
    updated_at_utc timestamp without time zone NOT NULL,
    niche text,
    scrape_interval_seconds integer DEFAULT 300 NOT NULL,
    last_scraped_at timestamp with time zone,
    domain text
);


--
-- Name: listing_attribute_v; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.listing_attribute_v AS
 SELECT l.id AS listing_id,
    l.external_id,
    l.source_id,
    l.source AS source_label,
    l.model_key,
    l.brand,
    l.product_family,
    l.model_name,
    l.storage_gb,
    l.colour,
    l.epid,
    l.raw_attrs
   FROM (public.auction_listings l
     JOIN public.sources s ON ((s.id = l.source_id)));


--
-- Name: VIEW listing_attribute_v; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON VIEW public.listing_attribute_v IS 'Read-only extracted product attribute projection over auction_listings.';


--
-- Name: listing_backfill_audit_v; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.listing_backfill_audit_v AS
 SELECT l.id AS listing_id,
    l.external_id,
    l.source_id,
    l.source AS source_label,
    l.status,
    l.finalized,
    l.backfill_checked_at,
    l.backfill_check_count,
    l.backfill_last_reason
   FROM (public.auction_listings l
     JOIN public.sources s ON ((s.id = l.source_id)));


--
-- Name: VIEW listing_backfill_audit_v; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON VIEW public.listing_backfill_audit_v IS 'Read-only Point Zero backfill audit projection over auction_listings.';


--
-- Name: listing_identity_v; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.listing_identity_v AS
 SELECT l.id AS listing_id,
    l.external_id,
    l.source_id,
    l.source AS source_label,
    s.name AS canonical_source_name,
    s.domain AS canonical_source_domain,
    s.niche AS source_niche,
    l.title,
    l.url,
    l.sale_type,
    l.first_seen
   FROM (public.auction_listings l
     JOIN public.sources s ON ((s.id = l.source_id)));


--
-- Name: VIEW listing_identity_v; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON VIEW public.listing_identity_v IS 'Read-only listing identity projection over auction_listings and sources.';


--
-- Name: listing_inspection_checks_v; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.listing_inspection_checks_v WITH (security_barrier='true') AS
 SELECT a.id AS listing_id,
    check_entry.kind,
    check_entry.checked_at,
    check_entry.grade,
    check_entry.confidence,
    check_entry.review_required,
    check_entry.model_name,
    check_entry.flags,
    check_entry.verdict,
    check_entry.recommended_max_bid
   FROM (public.auction_listings a
     CROSS JOIN LATERAL ( SELECT 'text'::text AS kind,
            (t.checked_at AT TIME ZONE 'UTC'::text) AS checked_at,
            t.text_grade AS grade,
            t.confidence,
            t.review_required,
            public.ghostfrog_product_text(t.model_name) AS model_name,
            ARRAY( SELECT public.ghostfrog_product_text(flag.flag) AS ghostfrog_product_text
                   FROM unnest(t.risk_flags) flag(flag)) AS flags,
            NULL::text AS verdict,
            NULL::numeric AS recommended_max_bid
           FROM public.latest_listing_text_conditions t
          WHERE (t.listing_id = a.id)
        UNION ALL
         SELECT 'visual'::text,
            (v.checked_at AT TIME ZONE 'UTC'::text) AS timezone,
            v.visual_grade,
            v.confidence,
            v.review_required,
            public.ghostfrog_product_text(v.model_name) AS ghostfrog_product_text,
            ARRAY( SELECT public.ghostfrog_product_text(flag.flag) AS ghostfrog_product_text
                   FROM unnest(v.damage_flags) flag(flag)) AS "array",
            NULL::text,
            NULL::numeric
           FROM public.latest_listing_visual_conditions v
          WHERE (v.listing_id = a.id)
        UNION ALL
        ( SELECT 'listing_assessment'::text,
            x.created_at,
            NULL::text,
            (x.confidence)::numeric AS confidence,
            NULL::boolean,
            NULL::text,
            NULL::text[],
            public.ghostfrog_product_text(x.verdict) AS ghostfrog_product_text,
            x.recommended_max_bid
           FROM public.listing_assessments x
          WHERE (x.listing_id = a.id)
          ORDER BY x.created_at DESC, x.id DESC
         LIMIT 1)) check_entry)
  WHERE ((a.source ~ '^ebay-[a-z0-9-]+$'::text) AND (a.external_id ~ '^([0-9]{12}|v1[|][0-9]{12}[|][0-9]{1,12})$'::text));


--
-- Name: listing_inspection_evidence_v; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.listing_inspection_evidence_v WITH (security_barrier='true') AS
 SELECT a.id AS listing_id,
    e.section,
    e.evidence_seq,
    e.field,
    e.value_path,
    e.part_index,
    e.part_count,
    e.value,
    e.withheld,
    (e.value #>> '{}'::text[]) AS value_text,
    (e.value_path)::text AS value_path_text
   FROM ((((public.auction_listings a
     LEFT JOIN public.latest_listing_text_conditions tc ON ((tc.listing_id = a.id)))
     LEFT JOIN public.latest_listing_visual_conditions vc ON ((vc.listing_id = a.id)))
     LEFT JOIN LATERAL ( SELECT x.assessment
           FROM public.listing_assessments x
          WHERE (x.listing_id = a.id)
          ORDER BY x.created_at DESC, x.id DESC
         LIMIT 1) ass ON (true))
     CROSS JOIN LATERAL public.ghostfrog_listing_evidence(a.title, a.notes, a.raw_attrs, jsonb_strip_nulls(jsonb_build_object('text assessment', tc.assessment, 'visual assessment', vc.assessment, 'listing assessment', ass.assessment, 'text risk flags', to_jsonb(tc.risk_flags), 'visual damage flags', to_jsonb(vc.damage_flags)))) e(section, evidence_seq, field, value_path, part_index, part_count, value, withheld))
  WHERE ((a.source ~ '^ebay-[a-z0-9-]+$'::text) AND (a.external_id ~ '^([0-9]{12}|v1[|][0-9]{12}[|][0-9]{1,12})$'::text));


--
-- Name: listing_inspection_v; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.listing_inspection_v WITH (security_barrier='true') AS
 SELECT a.id AS listing_id,
    a.external_id,
    a.source,
        CASE
            WHEN (a.external_id ~~ 'v1|%'::text) THEN split_part(a.external_id, '|'::text, 2)
            ELSE a.external_id
        END AS item_id,
    public.ghostfrog_product_text(a.title) AS title,
    public.ghostfrog_marketplace_url(a.url, false) AS url,
    ((a.url IS NOT NULL) AND (public.ghostfrog_marketplace_url(a.url, false) IS NULL)) AS url_withheld,
    public.ghostfrog_product_text(a.sale_type) AS sale_type,
    public.ghostfrog_product_text(a.status) AS status,
    a.finalized,
    a.first_seen,
    a.last_seen_at,
    a.fetched_at,
    a.price_current,
    a.price_bid_current,
    a.bids_count,
    a.end_time,
    a.condition_grade,
        CASE
            WHEN ((a.status = 'sold'::text) AND (a.final_price > (0)::numeric) AND (a.end_time <= now())) THEN a.final_price
            ELSE NULL::numeric
        END AS final_price,
        CASE
            WHEN ((a.model_key ~ '^[A-Za-z0-9][A-Za-z0-9_.+-]{0,159}$'::text) AND (a.model_key !~* '^(sk[-_]|bearer|gh[pousr]_|github_pat_|xox[baprs]-|AKIA[0-9A-Z]|eyJ[A-Za-z0-9_-])'::text)) THEN a.model_key
            ELSE NULL::text
        END AS model_key,
    a.storage_gb,
    public.ghostfrog_listing_identity_risk(a.title, a.raw_attrs) AS identity_risk,
    public.ghostfrog_product_text(a.brand) AS brand,
    public.ghostfrog_product_text(a.product_family) AS product_family,
    public.ghostfrog_product_text(a.model_name) AS model_name,
    public.ghostfrog_product_text(a.colour) AS colour,
        CASE
            WHEN (a.roi_estimate <> ALL (ARRAY['NaN'::double precision, 'Infinity'::double precision, '-Infinity'::double precision])) THEN a.roi_estimate
            ELSE NULL::double precision
        END AS roi_estimate,
    a.max_bid,
    a.backfill_checked_at,
    a.backfill_check_count,
    public.ghostfrog_product_text(a.backfill_last_reason) AS backfill_last_reason,
    a.text_condition_checked,
    a.text_condition_checked_at,
    a.visual_condition_checked,
    a.visual_condition_checked_at,
        CASE
            WHEN (a.raw_attrs ? 'Browse description'::text) THEN length((a.raw_attrs ->> 'Browse description'::text))
            ELSE NULL::integer
        END AS browse_description_chars,
        CASE
            WHEN (jsonb_typeof((a.raw_attrs -> 'Browse imageUrls'::text)) = 'array'::text) THEN jsonb_array_length((a.raw_attrs -> 'Browse imageUrls'::text))
            ELSE NULL::integer
        END AS stored_gallery_count,
        CASE
            WHEN (jsonb_typeof((a.raw_attrs -> 'Browse shippingOptions'::text)) = 'array'::text) THEN jsonb_array_length((a.raw_attrs -> 'Browse shippingOptions'::text))
            ELSE NULL::integer
        END AS stored_shipping_option_count,
    tc.text_grade,
    vc.visual_grade,
        CASE
            WHEN ((('D'::text = upper(COALESCE(a.condition_grade, 'B'::text))) OR ('D'::text = upper(tc.text_grade))) OR ('D'::text = upper(vc.visual_grade))) THEN 'D'::text
            WHEN ((('C'::text = upper(COALESCE(a.condition_grade, 'B'::text))) OR ('C'::text = upper(tc.text_grade))) OR ('C'::text = upper(vc.visual_grade))) THEN 'C'::text
            WHEN ((('B'::text = upper(COALESCE(a.condition_grade, 'B'::text))) OR ('B'::text = upper(tc.text_grade))) OR ('B'::text = upper(vc.visual_grade))) THEN 'B'::text
            ELSE 'A'::text
        END AS effective_condition_grade,
    tc.review_required AS text_review_required,
    vc.review_required AS visual_review_required,
    a.source_id
   FROM ((public.auction_listings a
     LEFT JOIN public.latest_listing_text_conditions tc ON ((tc.listing_id = a.id)))
     LEFT JOIN public.latest_listing_visual_conditions vc ON ((vc.listing_id = a.id)))
  WHERE ((a.source ~ '^ebay-[a-z0-9-]+$'::text) AND (a.external_id ~ '^([0-9]{12}|v1[|][0-9]{12}[|][0-9]{1,12})$'::text));


--
-- Name: listing_observation_v; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.listing_observation_v AS
 SELECT l.id AS listing_id,
    l.external_id,
    l.source_id,
    l.source AS source_label,
    l.price_current,
    l.price_bid_current,
    l.bids_count,
    l.end_time,
    l.time_left_s,
    l.first_seen,
    l.last_seen_at,
    l.fetched_at
   FROM (public.auction_listings l
     JOIN public.sources s ON ((s.id = l.source_id)));


--
-- Name: VIEW listing_observation_v; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON VIEW public.listing_observation_v IS 'Read-only current observation projection for listing price, bid, and freshness fields.';


--
-- Name: listing_statuses; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.listing_statuses (
    status text NOT NULL,
    lifecycle_stage text NOT NULL,
    is_live_like boolean DEFAULT false NOT NULL,
    is_terminal boolean DEFAULT false NOT NULL,
    description text NOT NULL,
    created_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: listing_outcome_v; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.listing_outcome_v AS
 SELECT l.id AS listing_id,
    l.external_id,
    l.source_id,
    l.source AS source_label,
    l.status,
    st.lifecycle_stage,
    st.is_live_like,
    st.is_terminal,
    l.finalized,
    l.final_price,
    l.bids_count AS final_or_latest_bids_count,
    l.end_time
   FROM ((public.auction_listings l
     JOIN public.listing_statuses st ON ((st.status = l.status)))
     JOIN public.sources s ON ((s.id = l.source_id)));


--
-- Name: VIEW listing_outcome_v; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON VIEW public.listing_outcome_v IS 'Read-only outcome projection for status, finalization, and final-price fields.';


--
-- Name: listing_review_actions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.listing_review_actions (
    id bigint NOT NULL,
    source text,
    external_id text NOT NULL,
    title text,
    url text,
    action text NOT NULL,
    created_at timestamp with time zone DEFAULT (now() AT TIME ZONE 'utc'::text) NOT NULL,
    CONSTRAINT listing_review_actions_action_check CHECK ((action = ANY (ARRAY['up'::text, 'down'::text])))
);


--
-- Name: listing_review_actions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.listing_review_actions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: listing_review_actions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.listing_review_actions_id_seq OWNED BY public.listing_review_actions.id;


--
-- Name: listing_score_v; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.listing_score_v AS
 SELECT l.id AS listing_id,
    l.external_id,
    l.source_id,
    l.source AS source_label,
    l.model_key,
    l.roi_estimate,
    l.max_bid,
    l.bucket_key,
    l.notes
   FROM (public.auction_listings l
     JOIN public.sources s ON ((s.id = l.source_id)));


--
-- Name: VIEW listing_score_v; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON VIEW public.listing_score_v IS 'Read-only scoring projection for legacy ROI and max-bid fields.';


--
-- Name: listing_text_condition_queue; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.listing_text_condition_queue (
    id bigint NOT NULL,
    listing_id bigint NOT NULL,
    external_id text NOT NULL,
    source text NOT NULL,
    status_name text NOT NULL,
    priority_flag text DEFAULT 'backfill'::text NOT NULL,
    priority bigint DEFAULT 0 NOT NULL,
    status text DEFAULT 'pending'::text NOT NULL,
    attempts integer DEFAULT 0 NOT NULL,
    last_error text,
    queued_at timestamp without time zone DEFAULT (now() AT TIME ZONE 'utc'::text) NOT NULL,
    locked_at timestamp without time zone,
    processed_at timestamp without time zone
);


--
-- Name: listing_text_condition_queue_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.listing_text_condition_queue_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: listing_text_condition_queue_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.listing_text_condition_queue_id_seq OWNED BY public.listing_text_condition_queue.id;


--
-- Name: listing_text_conditions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.listing_text_conditions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: listing_text_conditions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.listing_text_conditions_id_seq OWNED BY public.listing_text_conditions.id;


--
-- Name: listing_visual_condition_queue; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.listing_visual_condition_queue (
    id bigint NOT NULL,
    listing_id bigint NOT NULL,
    external_id text NOT NULL,
    source text NOT NULL,
    product text NOT NULL,
    priority_flag text DEFAULT 'backfill'::text NOT NULL,
    priority bigint DEFAULT 0 NOT NULL,
    status text DEFAULT 'pending'::text NOT NULL,
    attempts integer DEFAULT 0 NOT NULL,
    last_error text,
    queued_at timestamp without time zone DEFAULT (now() AT TIME ZONE 'utc'::text) NOT NULL,
    locked_at timestamp without time zone,
    processed_at timestamp without time zone
);


--
-- Name: listing_visual_condition_queue_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.listing_visual_condition_queue_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: listing_visual_condition_queue_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.listing_visual_condition_queue_id_seq OWNED BY public.listing_visual_condition_queue.id;


--
-- Name: listing_visual_conditions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.listing_visual_conditions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: listing_visual_conditions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.listing_visual_conditions_id_seq OWNED BY public.listing_visual_conditions.id;


--
-- Name: mini_project_alerts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.mini_project_alerts (
    external_id text NOT NULL,
    title text NOT NULL,
    source text NOT NULL,
    model_key text,
    price_current numeric(10,2),
    first_detected_at timestamp with time zone NOT NULL,
    last_detected_at timestamp with time zone NOT NULL,
    email_sent_at timestamp with time zone,
    telegram_sent_at timestamp with time zone,
    payload jsonb DEFAULT '{}'::jsonb NOT NULL
);


--
-- Name: msx125_alerts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.msx125_alerts (
    external_id text NOT NULL,
    title text NOT NULL,
    source text NOT NULL,
    model_key text,
    price_current numeric(10,2),
    first_detected_at timestamp with time zone NOT NULL,
    last_detected_at timestamp with time zone NOT NULL,
    email_sent_at timestamp with time zone,
    payload jsonb DEFAULT '{}'::jsonb NOT NULL
);


--
-- Name: paper_trade_predictions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.paper_trade_predictions (
    id bigint NOT NULL,
    created_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    prediction_date date DEFAULT CURRENT_DATE NOT NULL,
    external_id text NOT NULL,
    source text NOT NULL,
    title text NOT NULL,
    url text,
    model_key text,
    current_price numeric(12,2) NOT NULL,
    predicted_resale_price numeric(12,2) NOT NULL,
    predicted_fees numeric(12,2) NOT NULL,
    predicted_outbound_shipping numeric(12,2) NOT NULL,
    predicted_profit numeric(12,2) NOT NULL,
    predicted_roi numeric(10,4) NOT NULL,
    recommended_max_bid numeric(12,2) NOT NULL,
    comp_samples integer NOT NULL,
    end_time timestamp with time zone,
    time_left_s integer,
    decision_status text DEFAULT 'watching'::text NOT NULL,
    observed_status text,
    observed_final_price numeric(12,2),
    observed_at timestamp with time zone,
    observed_price_error numeric(12,2),
    observed_paper_profit numeric(12,2),
    observed_paper_roi numeric(10,4),
    outcome_reason text,
    outcome_checked_at timestamp with time zone,
    outcome_check_count integer DEFAULT 0 NOT NULL,
    outcome_last_reason text,
    predicted_inbound_shipping numeric(12,2) DEFAULT 0 NOT NULL,
    predicted_packaging numeric(12,2) DEFAULT 0 NOT NULL,
    predicted_selling_reserve numeric(12,2) DEFAULT 0 NOT NULL,
    observed_purchase_price numeric(12,2),
    would_have_bid boolean,
    would_have_won boolean
);


--
-- Name: paper_trade_predictions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.paper_trade_predictions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: paper_trade_predictions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.paper_trade_predictions_id_seq OWNED BY public.paper_trade_predictions.id;


--
-- Name: pipeline_runtime_flags; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.pipeline_runtime_flags (
    name text NOT NULL,
    is_active boolean DEFAULT false NOT NULL,
    payload jsonb,
    updated_at timestamp with time zone DEFAULT (now() AT TIME ZONE 'utc'::text) NOT NULL
);


--
-- Name: prices; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.prices (
    id integer NOT NULL,
    source_id integer NOT NULL,
    symbol text NOT NULL,
    price numeric(18,8) NOT NULL,
    currency text DEFAULT 'USD'::text NOT NULL,
    fetched_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    created_at_utc timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at_utc timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: prices_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.prices_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: prices_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.prices_id_seq OWNED BY public.prices.id;


--
-- Name: roi_alert_markers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.roi_alert_markers (
    external_id text NOT NULL,
    marker text NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: roi_snapshots; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.roi_snapshots (
    id bigint NOT NULL,
    external_id text NOT NULL,
    source text NOT NULL,
    model_key text,
    current_price numeric(10,2) NOT NULL,
    roi_estimate numeric(10,2) NOT NULL,
    profit_estimate numeric(10,2) NOT NULL,
    ends_at timestamp with time zone,
    time_left_s integer,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    bucket_key text
);


--
-- Name: roi_snapshots_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.roi_snapshots_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: roi_snapshots_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.roi_snapshots_id_seq OWNED BY public.roi_snapshots.id;


--
-- Name: schema_migrations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.schema_migrations (
    name text NOT NULL,
    checksum text NOT NULL,
    applied_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: scrape_state; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.scrape_state (
    id integer NOT NULL,
    last_scrape timestamp with time zone,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: scrape_state_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.scrape_state_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: scrape_state_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.scrape_state_id_seq OWNED BY public.scrape_state.id;


--
-- Name: sources_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.sources_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: sources_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.sources_id_seq OWNED BY public.sources.id;


--
-- Name: alerts id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.alerts ALTER COLUMN id SET DEFAULT nextval('public.alerts_id_seq'::regclass);


--
-- Name: api_usage id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.api_usage ALTER COLUMN id SET DEFAULT nextval('public.api_usage_id_seq'::regclass);


--
-- Name: articles id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.articles ALTER COLUMN id SET DEFAULT nextval('public.articles_id_seq'::regclass);


--
-- Name: auction_listings id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.auction_listings ALTER COLUMN id SET DEFAULT nextval('public.auction_listings_id_seq'::regclass);


--
-- Name: auction_price_history id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.auction_price_history ALTER COLUMN id SET DEFAULT nextval('public.auction_price_history_id_seq'::regclass);


--
-- Name: listing_assessments id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.listing_assessments ALTER COLUMN id SET DEFAULT nextval('public.listing_assessments_id_seq'::regclass);


--
-- Name: listing_review_actions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.listing_review_actions ALTER COLUMN id SET DEFAULT nextval('public.listing_review_actions_id_seq'::regclass);


--
-- Name: listing_text_condition_queue id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.listing_text_condition_queue ALTER COLUMN id SET DEFAULT nextval('public.listing_text_condition_queue_id_seq'::regclass);


--
-- Name: listing_text_conditions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.listing_text_conditions ALTER COLUMN id SET DEFAULT nextval('public.listing_text_conditions_id_seq'::regclass);


--
-- Name: listing_visual_condition_queue id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.listing_visual_condition_queue ALTER COLUMN id SET DEFAULT nextval('public.listing_visual_condition_queue_id_seq'::regclass);


--
-- Name: listing_visual_conditions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.listing_visual_conditions ALTER COLUMN id SET DEFAULT nextval('public.listing_visual_conditions_id_seq'::regclass);


--
-- Name: paper_trade_predictions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.paper_trade_predictions ALTER COLUMN id SET DEFAULT nextval('public.paper_trade_predictions_id_seq'::regclass);


--
-- Name: prices id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.prices ALTER COLUMN id SET DEFAULT nextval('public.prices_id_seq'::regclass);


--
-- Name: roi_snapshots id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.roi_snapshots ALTER COLUMN id SET DEFAULT nextval('public.roi_snapshots_id_seq'::regclass);


--
-- Name: scrape_state id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.scrape_state ALTER COLUMN id SET DEFAULT nextval('public.scrape_state_id_seq'::regclass);


--
-- Name: sources id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sources ALTER COLUMN id SET DEFAULT nextval('public.sources_id_seq'::regclass);


--
-- Name: alert_state alert_state_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.alert_state
    ADD CONSTRAINT alert_state_pkey PRIMARY KEY (name);


--
-- Name: alerts alerts_external_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.alerts
    ADD CONSTRAINT alerts_external_id_key UNIQUE (external_id);


--
-- Name: alerts alerts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.alerts
    ADD CONSTRAINT alerts_pkey PRIMARY KEY (id);


--
-- Name: api_usage api_usage_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.api_usage
    ADD CONSTRAINT api_usage_pkey PRIMARY KEY (id);


--
-- Name: api_usage api_usage_service_date_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.api_usage
    ADD CONSTRAINT api_usage_service_date_key UNIQUE (service, date);


--
-- Name: articles articles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.articles
    ADD CONSTRAINT articles_pkey PRIMARY KEY (id);


--
-- Name: articles articles_url_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.articles
    ADD CONSTRAINT articles_url_key UNIQUE (url);


--
-- Name: auction_listings auction_listings_external_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.auction_listings
    ADD CONSTRAINT auction_listings_external_id_key UNIQUE (external_id);


--
-- Name: auction_listings auction_listings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.auction_listings
    ADD CONSTRAINT auction_listings_pkey PRIMARY KEY (id);


--
-- Name: auction_price_history_orphans auction_price_history_orphans_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.auction_price_history_orphans
    ADD CONSTRAINT auction_price_history_orphans_pkey PRIMARY KEY (original_id);


--
-- Name: auction_price_history auction_price_history_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.auction_price_history
    ADD CONSTRAINT auction_price_history_pkey PRIMARY KEY (id);


--
-- Name: comps comps_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.comps
    ADD CONSTRAINT comps_pkey PRIMARY KEY (model_key, condition_grade);


--
-- Name: ebay_app_token ebay_app_token_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ebay_app_token
    ADD CONSTRAINT ebay_app_token_pkey PRIMARY KEY (id);


--
-- Name: listing_assessments listing_assessments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.listing_assessments
    ADD CONSTRAINT listing_assessments_pkey PRIMARY KEY (id);


--
-- Name: listing_review_actions listing_review_actions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.listing_review_actions
    ADD CONSTRAINT listing_review_actions_pkey PRIMARY KEY (id);


--
-- Name: listing_statuses listing_statuses_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.listing_statuses
    ADD CONSTRAINT listing_statuses_pkey PRIMARY KEY (status);


--
-- Name: listing_text_condition_queue listing_text_condition_queue_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.listing_text_condition_queue
    ADD CONSTRAINT listing_text_condition_queue_pkey PRIMARY KEY (id);


--
-- Name: listing_text_conditions listing_text_conditions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.listing_text_conditions
    ADD CONSTRAINT listing_text_conditions_pkey PRIMARY KEY (id);


--
-- Name: listing_visual_condition_queue listing_visual_condition_queue_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.listing_visual_condition_queue
    ADD CONSTRAINT listing_visual_condition_queue_pkey PRIMARY KEY (id);


--
-- Name: listing_visual_conditions listing_visual_conditions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.listing_visual_conditions
    ADD CONSTRAINT listing_visual_conditions_pkey PRIMARY KEY (id);


--
-- Name: mini_project_alerts mini_project_alerts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.mini_project_alerts
    ADD CONSTRAINT mini_project_alerts_pkey PRIMARY KEY (external_id);


--
-- Name: msx125_alerts msx125_alerts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.msx125_alerts
    ADD CONSTRAINT msx125_alerts_pkey PRIMARY KEY (external_id);


--
-- Name: paper_trade_predictions paper_trade_predictions_external_id_prediction_date_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.paper_trade_predictions
    ADD CONSTRAINT paper_trade_predictions_external_id_prediction_date_key UNIQUE (external_id, prediction_date);


--
-- Name: paper_trade_predictions paper_trade_predictions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.paper_trade_predictions
    ADD CONSTRAINT paper_trade_predictions_pkey PRIMARY KEY (id);


--
-- Name: pipeline_runtime_flags pipeline_runtime_flags_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pipeline_runtime_flags
    ADD CONSTRAINT pipeline_runtime_flags_pkey PRIMARY KEY (name);


--
-- Name: prices prices_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.prices
    ADD CONSTRAINT prices_pkey PRIMARY KEY (id);


--
-- Name: roi_alert_markers roi_alert_markers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.roi_alert_markers
    ADD CONSTRAINT roi_alert_markers_pkey PRIMARY KEY (external_id, marker);


--
-- Name: roi_snapshots roi_snapshots_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.roi_snapshots
    ADD CONSTRAINT roi_snapshots_pkey PRIMARY KEY (id);


--
-- Name: schema_migrations schema_migrations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.schema_migrations
    ADD CONSTRAINT schema_migrations_pkey PRIMARY KEY (name);


--
-- Name: scrape_state scrape_state_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.scrape_state
    ADD CONSTRAINT scrape_state_pkey PRIMARY KEY (id);


--
-- Name: sources sources_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sources
    ADD CONSTRAINT sources_name_key UNIQUE (name);


--
-- Name: sources sources_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sources
    ADD CONSTRAINT sources_pkey PRIMARY KEY (id);


--
-- Name: aph_ext_time_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX aph_ext_time_idx ON public.auction_price_history USING btree (external_id, recorded_at DESC);


--
-- Name: auction_listings_source_id_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX auction_listings_source_id_idx ON public.auction_listings USING btree (source_id);


--
-- Name: auction_listings_text_condition_checked_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX auction_listings_text_condition_checked_idx ON public.auction_listings USING btree (text_condition_checked, first_seen DESC) WHERE (status = 'live'::text);


--
-- Name: auction_listings_visual_condition_checked_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX auction_listings_visual_condition_checked_idx ON public.auction_listings USING btree (visual_condition_checked, first_seen DESC) WHERE (status = 'live'::text);


--
-- Name: auction_price_history_orphans_external_id_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX auction_price_history_orphans_external_id_idx ON public.auction_price_history_orphans USING btree (external_id);


--
-- Name: auction_price_history_orphans_recorded_at_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX auction_price_history_orphans_recorded_at_idx ON public.auction_price_history_orphans USING btree (recorded_at DESC);


--
-- Name: idx_alerts_external; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_alerts_external ON public.alerts USING btree (external_id);


--
-- Name: idx_auction_backfill_checked_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_auction_backfill_checked_at ON public.auction_listings USING btree (backfill_checked_at DESC) INCLUDE (status, finalized, backfill_last_reason) WHERE (backfill_checked_at IS NOT NULL);


--
-- Name: idx_auction_direct_backfill_retryable_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_auction_direct_backfill_retryable_id ON public.auction_listings USING btree (id) INCLUDE (external_id, source, status, finalized, model_key, title, price_current, bids_count, price_bid_current, sale_type, end_time, last_seen_at, backfill_checked_at, backfill_check_count, backfill_last_reason) WHERE (((final_price IS NULL) OR (final_price <= (0)::numeric)) AND (end_time IS NOT NULL) AND (backfill_checked_at IS NOT NULL) AND (NOT ((status = 'ended'::text) AND (COALESCE(finalized, false) = true))) AND (COALESCE(backfill_last_reason, ''::text) <> 'invalid_external_id'::text) AND (external_id ~ '[0-9]'::text));


--
-- Name: idx_auction_direct_backfill_unchecked_end_time; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_auction_direct_backfill_unchecked_end_time ON public.auction_listings USING btree (end_time) WHERE (((final_price IS NULL) OR (final_price <= (0)::numeric)) AND (end_time IS NOT NULL) AND (backfill_checked_at IS NULL) AND (external_id ~ '[0-9]'::text));


--
-- Name: idx_auction_direct_backfill_unchecked_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_auction_direct_backfill_unchecked_id ON public.auction_listings USING btree (id) INCLUDE (external_id, source, status, finalized, model_key, title, price_current, bids_count, price_bid_current, sale_type, end_time, last_seen_at, backfill_check_count, backfill_last_reason) WHERE (((final_price IS NULL) OR (final_price <= (0)::numeric)) AND (end_time IS NOT NULL) AND (backfill_checked_at IS NULL) AND (external_id ~ '[0-9]'::text));


--
-- Name: idx_auction_end_time; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_auction_end_time ON public.auction_listings USING btree (end_time);


--
-- Name: idx_auction_final_price_known_model_key_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_auction_final_price_known_model_key_id ON public.auction_listings USING btree (id) WHERE ((final_price IS NOT NULL) AND (final_price > (0)::numeric) AND (model_key IS NOT NULL) AND (model_key <> ''::text) AND (lower(model_key) <> 'unknown'::text) AND (lower(model_key) !~~ 'unknown%'::text));


--
-- Name: idx_auction_final_price_positive_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_auction_final_price_positive_id ON public.auction_listings USING btree (id) WHERE ((final_price IS NOT NULL) AND (final_price > (0)::numeric));


--
-- Name: idx_auction_listings_fetched_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_auction_listings_fetched_at ON public.auction_listings USING btree (fetched_at DESC);


--
-- Name: idx_auction_live_last_seen; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_auction_live_last_seen ON public.auction_listings USING btree (last_seen_at) WHERE ((status = 'live'::text) AND (last_seen_at IS NOT NULL));


--
-- Name: idx_auction_live_model_condition; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_auction_live_model_condition ON public.auction_listings USING btree (model_key, condition_grade) WHERE ((status = 'live'::text) AND (price_current IS NOT NULL) AND (price_current > 0) AND (model_key IS NOT NULL) AND (model_key <> ''::text) AND (lower(model_key) <> 'unknown'::text));


--
-- Name: idx_auction_live_model_key_source; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_auction_live_model_key_source ON public.auction_listings USING btree (source, model_key) WHERE ((status = 'live'::text) AND (model_key IS NOT NULL) AND (model_key <> ''::text));


--
-- Name: idx_auction_live_refresh_end_seen; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_auction_live_refresh_end_seen ON public.auction_listings USING btree (end_time, last_seen_at, source_id) INCLUDE (external_id) WHERE ((status = 'live'::text) AND (COALESCE(finalized, false) = false) AND (end_time IS NOT NULL) AND (last_seen_at IS NOT NULL));


--
-- Name: idx_auction_missing_model_key_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_auction_missing_model_key_id ON public.auction_listings USING btree (id) WHERE ((model_key IS NULL) OR (model_key = ''::text) OR (lower(model_key) = 'unknown'::text) OR (lower(model_key) ~~ 'unknown%'::text));


--
-- Name: idx_auction_model_condition_sold_final; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_auction_model_condition_sold_final ON public.auction_listings USING btree (model_key, condition_grade) WHERE ((status = 'sold'::text) AND (final_price IS NOT NULL) AND (final_price > (0)::numeric) AND (model_key IS NOT NULL) AND (model_key <> ''::text) AND (lower(model_key) <> 'unknown'::text));


--
-- Name: idx_auction_model_key; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_auction_model_key ON public.auction_listings USING btree (model_key);


--
-- Name: idx_auction_raw_attrs_null_id_desc; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_auction_raw_attrs_null_id_desc ON public.auction_listings USING btree (id DESC) INCLUDE (external_id, source, title) WHERE (raw_attrs IS NULL);


--
-- Name: idx_auction_source; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_auction_source ON public.auction_listings USING btree (source);


--
-- Name: idx_auction_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_auction_status ON public.auction_listings USING btree (status);


--
-- Name: idx_auction_status_end; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_auction_status_end ON public.auction_listings USING btree (status, end_time);


--
-- Name: idx_auction_status_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_auction_status_id ON public.auction_listings USING btree (status, id);


--
-- Name: idx_auction_unfinalized_live_end_time; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_auction_unfinalized_live_end_time ON public.auction_listings USING btree (end_time, id) INCLUDE (external_id, source) WHERE ((finalized = false) AND (status = ANY (ARRAY['active'::text, 'ending'::text, 'live'::text])) AND (end_time IS NOT NULL));


--
-- Name: idx_auction_unknown_backfill_candidates; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_auction_unknown_backfill_candidates ON public.auction_listings USING btree (source, model_key, end_time DESC, backfill_checked_at, last_seen_at DESC) INCLUDE (external_id, title, price_current, bids_count, price_bid_current, sale_type, backfill_check_count, backfill_last_reason) WHERE ((status = 'unknown'::text) AND (COALESCE(finalized, false) = false));


--
-- Name: idx_auction_unknown_not_due_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_auction_unknown_not_due_id ON public.auction_listings USING btree (id) WHERE ((status = 'unknown'::text) AND (COALESCE(finalized, false) = false) AND (end_time IS NOT NULL));


--
-- Name: idx_auction_unknown_unfinalized_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_auction_unknown_unfinalized_id ON public.auction_listings USING btree (id) WHERE ((status = 'unknown'::text) AND (COALESCE(finalized, false) = false));


--
-- Name: idx_latest_comps_model_condition; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_latest_comps_model_condition ON public.latest_comps USING btree (model_key, condition_grade);


--
-- Name: idx_pricehist_ext_time; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_pricehist_ext_time ON public.auction_price_history USING btree (external_id, recorded_at DESC);


--
-- Name: idx_sources_last_scraped; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_sources_last_scraped ON public.sources USING btree (last_scraped_at);


--
-- Name: listing_assessments_listing_id_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX listing_assessments_listing_id_idx ON public.listing_assessments USING btree (listing_id);


--
-- Name: listing_review_actions_action_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX listing_review_actions_action_idx ON public.listing_review_actions USING btree (action, created_at DESC);


--
-- Name: listing_review_actions_latest_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX listing_review_actions_latest_idx ON public.listing_review_actions USING btree (external_id, created_at DESC, id DESC);


--
-- Name: listing_text_condition_queue_listing_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX listing_text_condition_queue_listing_idx ON public.listing_text_condition_queue USING btree (listing_id);


--
-- Name: listing_text_condition_queue_pending_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX listing_text_condition_queue_pending_idx ON public.listing_text_condition_queue USING btree (status, priority DESC, queued_at);


--
-- Name: listing_text_conditions_external_latest_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX listing_text_conditions_external_latest_idx ON public.listing_text_conditions USING btree (external_id, checked_at DESC, id DESC);


--
-- Name: listing_text_conditions_listing_latest_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX listing_text_conditions_listing_latest_idx ON public.listing_text_conditions USING btree (listing_id, checked_at DESC, id DESC);


--
-- Name: listing_text_conditions_review_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX listing_text_conditions_review_idx ON public.listing_text_conditions USING btree (review_required, checked_at DESC);


--
-- Name: listing_visual_condition_queue_listing_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX listing_visual_condition_queue_listing_idx ON public.listing_visual_condition_queue USING btree (listing_id);


--
-- Name: listing_visual_condition_queue_pending_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX listing_visual_condition_queue_pending_idx ON public.listing_visual_condition_queue USING btree (status, priority DESC, queued_at);


--
-- Name: listing_visual_conditions_external_latest_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX listing_visual_conditions_external_latest_idx ON public.listing_visual_conditions USING btree (external_id, checked_at DESC, id DESC);


--
-- Name: listing_visual_conditions_listing_latest_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX listing_visual_conditions_listing_latest_idx ON public.listing_visual_conditions USING btree (listing_id, checked_at DESC, id DESC);


--
-- Name: listing_visual_conditions_review_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX listing_visual_conditions_review_idx ON public.listing_visual_conditions USING btree (review_required, checked_at DESC);


--
-- Name: paper_trade_predictions_outcome_due_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX paper_trade_predictions_outcome_due_idx ON public.paper_trade_predictions USING btree (decision_status, end_time, outcome_checked_at);


--
-- Name: paper_trade_predictions_status_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX paper_trade_predictions_status_idx ON public.paper_trade_predictions USING btree (decision_status, created_at);


--
-- Name: articles articles_source_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.articles
    ADD CONSTRAINT articles_source_id_fkey FOREIGN KEY (source_id) REFERENCES public.sources(id) ON DELETE CASCADE;


--
-- Name: auction_listings auction_listings_source_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.auction_listings
    ADD CONSTRAINT auction_listings_source_id_fkey FOREIGN KEY (source_id) REFERENCES public.sources(id);


--
-- Name: auction_listings auction_listings_status_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.auction_listings
    ADD CONSTRAINT auction_listings_status_fkey FOREIGN KEY (status) REFERENCES public.listing_statuses(status);


--
-- Name: auction_price_history auction_price_history_external_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.auction_price_history
    ADD CONSTRAINT auction_price_history_external_id_fkey FOREIGN KEY (external_id) REFERENCES public.auction_listings(external_id);


--
-- Name: listing_assessments listing_assessments_listing_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.listing_assessments
    ADD CONSTRAINT listing_assessments_listing_id_fkey FOREIGN KEY (listing_id) REFERENCES public.auction_listings(id) ON DELETE CASCADE;


--
-- Name: listing_text_condition_queue listing_text_condition_queue_listing_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.listing_text_condition_queue
    ADD CONSTRAINT listing_text_condition_queue_listing_id_fkey FOREIGN KEY (listing_id) REFERENCES public.auction_listings(id) ON DELETE CASCADE;


--
-- Name: listing_text_conditions listing_text_conditions_listing_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.listing_text_conditions
    ADD CONSTRAINT listing_text_conditions_listing_id_fkey FOREIGN KEY (listing_id) REFERENCES public.auction_listings(id) ON DELETE CASCADE;


--
-- Name: listing_visual_condition_queue listing_visual_condition_queue_listing_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.listing_visual_condition_queue
    ADD CONSTRAINT listing_visual_condition_queue_listing_id_fkey FOREIGN KEY (listing_id) REFERENCES public.auction_listings(id) ON DELETE CASCADE;


--
-- Name: listing_visual_conditions listing_visual_conditions_listing_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.listing_visual_conditions
    ADD CONSTRAINT listing_visual_conditions_listing_id_fkey FOREIGN KEY (listing_id) REFERENCES public.auction_listings(id) ON DELETE CASCADE;


--
-- Name: prices prices_source_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.prices
    ADD CONSTRAINT prices_source_id_fkey FOREIGN KEY (source_id) REFERENCES public.sources(id) ON DELETE CASCADE;


--
-- PostgreSQL database dump complete
--

\unrestrict KeYlTagP9NF5TEVTQb2vnQibOhIkbdoqOLixFQA8bphJJbsw4eH3UwzhrEIgtdY

