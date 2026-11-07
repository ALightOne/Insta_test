<?php
/**
 * Builds docs/index.html from the latest public posts of the accounts in accounts.json.
 *
 * Exit codes:
 *   0 - page generated (some accounts may have failed; they are reported as warnings)
 *   1 - nothing usable was fetched, or configuration is invalid; docs/index.html is NOT touched
 *
 * Environment variables (all optional):
 *   INSTAGRAM_SESSIONID  value of the "sessionid" cookie of a logged-in Instagram account.
 *                        Instagram often refuses anonymous requests from datacenter IPs
 *                        (GitHub Actions included); with a session the requests are far more reliable.
 *   TIMEZONE             IANA time zone used for the displayed dates (default Europe/Bucharest).
 *   ITEMS_PER_ACCOUNT    how many posts to take from every account (default 6).
 *   INSTAGRAM_BASE_URL   override of https://www.instagram.com (used for local testing).
 */

const IG_APP_ID = '936619743392459';
const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';
const MAX_ATTEMPTS = 3;

$items_per_account = (int) (getenv('ITEMS_PER_ACCOUNT') ?: 6);
$columns = 3;
$timezone = getenv('TIMEZONE') ?: 'Europe/Bucharest';
$base_url = rtrim(getenv('INSTAGRAM_BASE_URL') ?: 'https://www.instagram.com', '/');
$session_id = getenv('INSTAGRAM_SESSIONID') ?: '';

function fail($message)
{
  fwrite(STDERR, "::error::$message\n");
  exit(1);
}

function warn($message)
{
  fwrite(STDERR, "::warning::$message\n");
}

function e($value)
{
  return htmlspecialchars((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function is_tag($string)
{
  return strpos($string, '#') === 0;
}

/**
 * Validates accounts.json and returns a list of usernames.
 */
function load_accounts($path)
{
  if(!is_readable($path))
  {
    fail("$path is missing or not readable.");
  }

  $accounts = json_decode(file_get_contents($path), true);
  if(json_last_error() !== JSON_ERROR_NONE)
  {
    fail("$path is not valid JSON: " . json_last_error_msg());
  }
  if(!is_array($accounts) || $accounts === [] || array_keys($accounts) !== range(0, count($accounts) - 1))
  {
    fail("$path must be a non-empty JSON list, e.g. [\"user1\", \"user2\"].");
  }

  $result = [];
  foreach($accounts as $i => $account)
  {
    if(!is_string($account) || trim($account) === '')
    {
      fail("$path: entry #$i must be a non-empty string.");
    }
    $account = trim($account);
    if(!is_tag($account) && !preg_match('/^[A-Za-z0-9._]{1,30}$/', $account))
    {
      fail("$path: \"$account\" is not a valid Instagram username.");
    }
    $result[] = $account;
  }

  return array_values(array_unique($result));
}

/**
 * GETs a JSON document from Instagram. Throws RuntimeException with a clear reason on failure,
 * in particular when Instagram answers 200 with an HTML page instead of JSON.
 */
function fetch_json($url, $session_id)
{
  $headers = [
    'Accept: application/json',
    'X-IG-App-ID: ' . IG_APP_ID,
    'X-Requested-With: XMLHttpRequest',
    'Referer: https://www.instagram.com/',
  ];
  if($session_id !== '')
  {
    $headers[] = 'Cookie: sessionid=' . $session_id;
  }

  for($attempt = 1; ; $attempt++)
  {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => false,
      CURLOPT_TIMEOUT => 30,
      CURLOPT_USERAGENT => USER_AGENT,
      CURLOPT_HTTPHEADER => $headers,
      CURLOPT_ENCODING => '',
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $content_type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    $retryable = $body === false || $status == 429 || $status >= 500;
    if($retryable && $attempt < MAX_ATTEMPTS)
    {
      sleep(5 * $attempt);
      continue;
    }
    break;
  }

  if($body === false)
  {
    throw new RuntimeException("network error: $curl_error");
  }
  if($status >= 300 && $status < 400)
  {
    throw new RuntimeException("HTTP $status redirect (usually to the login page) - Instagram wants an authenticated session; set INSTAGRAM_SESSIONID");
  }
  if($status == 401 || $status == 403)
  {
    throw new RuntimeException("HTTP $status - Instagram refused the anonymous request; set INSTAGRAM_SESSIONID");
  }
  if($status == 404)
  {
    throw new RuntimeException('HTTP 404 - account does not exist');
  }
  if($status == 429)
  {
    throw new RuntimeException('HTTP 429 - rate limited by Instagram');
  }
  if($status != 200)
  {
    throw new RuntimeException("unexpected HTTP $status");
  }

  $trimmed = ltrim($body);
  if(stripos($content_type, 'text/html') !== false || stripos($trimmed, '<!DOCTYPE') === 0 || stripos($trimmed, '<html') === 0)
  {
    throw new RuntimeException('HTTP 200 but the body is an HTML page, not JSON (login wall / blocked) - set INSTAGRAM_SESSIONID');
  }

  $data = json_decode($body, true);
  if(!is_array($data))
  {
    throw new RuntimeException('response is not valid JSON: ' . json_last_error_msg());
  }

  return $data;
}

/**
 * Returns a list of normalised posts for one public account.
 */
function fetch_account($username, $limit, $base_url, $session_id)
{
  $data = fetch_json($base_url . '/api/v1/users/web_profile_info/?username=' . rawurlencode($username), $session_id);

  $user = $data['data']['user'] ?? null;
  if(!is_array($user))
  {
    throw new RuntimeException('JSON has no data.user (account missing or response format changed)');
  }
  if(!empty($user['is_private']))
  {
    throw new RuntimeException('account is private');
  }

  $edges = $user['edge_owner_to_timeline_media']['edges'] ?? null;
  if(!is_array($edges))
  {
    throw new RuntimeException('JSON has no timeline media (response format changed?)');
  }

  $posts = [];
  foreach(array_slice($edges, 0, $limit) as $edge)
  {
    $node = $edge['node'] ?? [];
    if(empty($node['shortcode']) || empty($node['display_url']) || empty($node['taken_at_timestamp']))
    {
      continue;
    }
    $posts[] = [
      'account' => $username,
      'link' => 'https://www.instagram.com/p/' . $node['shortcode'] . '/',
      'src' => $node['display_url'],
      'is_video' => !empty($node['is_video']),
      'caption' => (string) ($node['edge_media_to_caption']['edges'][0]['node']['text'] ?? ''),
      'likes' => (int) ($node['edge_liked_by']['count'] ?? $node['edge_media_preview_like']['count'] ?? 0),
      'comments' => (int) ($node['edge_media_to_comment']['count'] ?? 0),
      'created' => (int) $node['taken_at_timestamp'],
    ];
  }

  return $posts;
}

// ---------------------------------------------------------------------------

try
{
  $tz = new DateTimeZone($timezone);
}
catch(Exception $ex)
{
  fail("TIMEZONE \"$timezone\" is not a valid time zone.");
}

$accounts = load_accounts(__DIR__ . '/accounts.json');

$items = [];
$failed = [];
foreach($accounts as $i => $account)
{
  if($i > 0)
  {
    sleep(2); // be gentle, Instagram rate-limits aggressively
  }

  if(is_tag($account))
  {
    $failed[] = $account;
    warn("$account: hashtags are no longer supported - Instagram requires login for tag pages.");
    continue;
  }

  try
  {
    $posts = fetch_account($account, $items_per_account, $base_url, $session_id);
  }
  catch(Exception $ex)
  {
    $failed[] = $account;
    warn("$account: " . $ex->getMessage());
    continue;
  }

  echo "$account: " . count($posts) . " posts\n";
  if($posts === [])
  {
    warn("$account: no posts found.");
  }
  foreach($posts as $post)
  {
    $items[] = $post;
  }
}

if($items === [])
{
  fail('No posts were fetched from any account (' . count($failed) . '/' . count($accounts) . ' failed). docs/index.html was left unchanged.');
}

usort($items, function($a, $b) { return $b['created'] <=> $a['created']; });

$tile_template = file_get_contents(__DIR__ . '/templates/tile-card.html');
$page_template = file_get_contents(__DIR__ . '/templates/index.html');
if($tile_template === false || $page_template === false)
{
  fail('Templates are missing.');
}

$tiles = [];
foreach(array_chunk($items, $columns) as $row)
{
  $html = '';
  foreach($row as $item)
  {
    $caption = trim(preg_replace('@#\S+@u', '', $item['caption']));
    if($item['is_video'])
    {
      $caption = 'Video: ' . $caption;
    }

    $created = (new DateTime('@' . $item['created']))->setTimezone($tz)->format('F j, Y g:i a');

    $html .= strtr($tile_template, [
      '{{ @src }}' => e($item['src']),
      '{{ @account }}' => e($item['account']),
      '{{ @account_link }}' => e('https://www.instagram.com/' . rawurlencode($item['account']) . '/'),
      '{{ @num_likes}}' => e($item['likes']),
      '{{ @num_comments }}' => e($item['comments']),
      '{{ @caption }}' => nl2br(e($caption)),
      '{{ @created_time }}' => e($created),
      '{{ @link }}' => e($item['link']),
    ]);
  }
  $tiles[] = '<div class="tile is-ancestor">' . $html . '</div>';
}

$generated = (new DateTime('now', $tz))->format('F j, Y g:i a T');
$page = strtr($page_template, [
  '{{ @tiles }}' => join("\n", $tiles),
  '{{ @generated }}' => e($generated),
]);

$out = __DIR__ . '/docs/index.html';
$tmp = $out . '.tmp';
if(file_put_contents($tmp, $page) === false || !rename($tmp, $out))
{
  fail("Could not write $out.");
}

echo 'Wrote ' . count($items) . ' posts to docs/index.html';
if($failed !== [])
{
  echo ' (failed accounts: ' . join(', ', $failed) . ')';
}
echo "\n";
exit(0);
