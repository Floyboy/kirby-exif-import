<?php
/**
 * site/plugins/exif-import/index.php
 */
namespace hnzio\ExifImport;

use Kirby\Cms\App as Kirby;  // korrekt: App alias Kirby
use Kirby\Cms\Page;
use Kirby\Cms\File;
use Kirby\Toolkit\Str;

if (function_exists(__NAMESPACE__ . '\\pluginOptions') === false) {
  function pluginOptions(): array
  {
    $defaultsFile = __DIR__ . '/config.sample.php';
    $localFile = __DIR__ . '/config.php';

    $defaults = is_file($defaultsFile) ? require $defaultsFile : [];
    $local = is_file($localFile) ? require $localFile : [];

    if (is_array($defaults) === false) {
      $defaults = [];
    }

    if (is_array($local) === false) {
      $local = [];
    }

    return array_replace_recursive($defaults, $local);
  }
}

if (function_exists(__NAMESPACE__ . '\\exifOption') === false) {
  function exifOption(string $key, mixed $default = null): mixed
  {
    return \option('hnzio.exif.' . $key, \option('hnz.exif.' . $key, $default));
  }
}

Kirby::plugin('hnzio/exif-import', [
  'options' => pluginOptions(),
  'hooks' => [
    // Upload nie blockieren: bei fehlenden GPS-Daten nur Hinweis loggen
    'file.create:before' => function (...$args) {
      try {
        if (exifOption('requireGpsOnUpload', false) !== true) {
          return;
        }

        $file = firstFileFromArgs(...$args);
        if (!$file || $file->type() !== 'image') {
          return;
        }

        $page = $file->page();
        if (!$page) {
          return;
        }

        $allowedTemplates = (array)exifOption('requireGpsTemplates', ['image', 'story', 'article']);
        if (!in_array($page->intendedTemplate()->name(), $allowedTemplates, true)) {
          return;
        }

        if (fileHasGpsMetadata($file) !== true) {
          error_log(
            '[Exif-Import] Hinweis: Datei ohne GPS hochgeladen (' . $file->filename() . ') auf Seite ' . $page->id() . '.'
          );
        }
      } catch (\Throwable $e) {
        error_log('[Exif-Import] Hinweis: GPS-Prüfung beim Upload fehlgeschlagen: ' . $e->getMessage());
      }
    },

    // Beim Anlegen: default.txt -> <intended>.txt (einmalig)
    'page.create:after' => function (...$args) {
      try {
        $page = firstPageFromArgs(...$args);
        if (!$page) {
          return;
        }
        normalizeTemplate($page); // ggf. default -> intended
      } catch (\Throwable $e) {
        error_log('[ForceTemplate CREATE] ' . ($page?->id() ?? 'n/a') . ': ' . $e->getMessage());
      }
    },

    // Seite gespeichert → EXIF nur aus dem Titelbild (kein Templatewechsel mehr!)
    'page.update:after' => function (...$args) {
      try {
        if (exifOption('autoSyncOnPageUpdate', false) !== true) {
          return;
        }

        $newPage = firstPageFromArgs(...$args);
        if (!$newPage) {
          return;
        }

        if (Guard::isLocked('page.update')) {
          return;
        }

        // Falls noch "default" → EXIF überspringen (Templatewechsel nur im CREATE-Hook)
        if ($newPage->template()->name() === 'default' && $newPage->intendedTemplate()->name() !== 'default') {
          error_log('[Exif-Import] Skip page.update: default template → ' . $newPage->id());
          return;
        }

        if ($newPage->content()->has('ExifImport') && $newPage->ExifImport()->toBool() !== true) {
          error_log('[Exif-Import] Skip page.update: ExifImport=false → ' . $newPage->id());
          return;
        }

        Guard::lock('page.update');
        try {
          $titleImage = resolveTitleImage($newPage);
          if ($titleImage) {
            exifImportForPage($newPage, $titleImage); // nur Titelbild
          } else {
            exifImportForPage($newPage); // Fallback (z.B. wenn gar kein Bild vorhanden)
          }
        } finally {
          Guard::unlock('page.update');
        }

      } catch (\Throwable $e) {
        error_log('[Exif-Import] Fehler page.update: ' . $e->getMessage() . ' (Seite: ' . ($newPage?->id() ?? 'n/a') . ')');
      }
    },

    // Datei gespeichert → nur diese Datei verarbeiten (kein Templatewechsel!)
    'file.update:after' => function (...$args) {
      try {
        if (exifOption('autoSyncOnFileUpdate', false) !== true) {
          return;
        }

        $newFile = firstFileFromArgs(...$args);
        if (!$newFile || $newFile->type() !== 'image') {
          return;
        }

        if (Guard::isLocked('file.update')) {
          return;
        }

        $page = $newFile->page();
        if (!$page) return;

        // Falls noch "default" → EXIF überspringen
        if ($page->template()->name() === 'default' && $page->intendedTemplate()->name() !== 'default') {
          error_log('[Exif-Import] Skip file.update: default template → ' . $page->id());
          return;
        }

        $toggleOk = $page->content()->has('ExifImport') ? $page->ExifImport()->toBool() : true;
        if (!$toggleOk) {
          error_log('[Exif-Import] Skip file.update: ExifImport=false → ' . $page->id());
          return;
        }

        Guard::lock('file.update');
        try {
          exifImportForPage($page, $newFile); // nur diese Datei
        } finally {
          Guard::unlock('file.update');
        }

      } catch (\Throwable $e) {
        error_log('[Exif-Import] Fehler file.update: ' . $e->getMessage());
      }
    },

    // Datei hochgeladen → EXIF sofort in Datei-.txt schreiben
    // (unabhängig vom ExifImport-Toggle auf der Seite)
    'file.create:after' => function (...$args) {
      try {
        $newFile = firstFileFromArgs(...$args);
        if (!$newFile || $newFile->type() !== 'image') {
          return;
        }

        if (Guard::isLocked('file.update')) {
          return;
        }

        $page = $newFile->page();
        if (!$page) return;

        // Falls noch "default" → EXIF überspringen
        if ($page->template()->name() === 'default' && $page->intendedTemplate()->name() !== 'default') {
          error_log('[Exif-Import] Skip file.create: default template → ' . $page->id());
          return;
        }

        Guard::lock('file.update');
        try {
          $updatedPage = exifImportForPage($page, $newFile); // nur hochgeladene Datei
          maybeSyncStoryTitleAndSlugFromImport($updatedPage);
        } finally {
          Guard::unlock('file.update');
        }

      } catch (\Throwable $e) {
        error_log('[Exif-Import] Fehler file.create: ' . $e->getMessage());
      }
    },
  ],
  'routes' => [
    [
      'pattern' => 'exif-import/run',
      'method'  => 'GET|POST',
      'action'  => function () {
        $request = \kirby()->request();
        $referer = (string)$request->header('referer');
        $panelBack = (str_contains($referer, '/panel/') ? $referer : null);
        $fallback = url('');
        $goBack = function (string $status) use ($panelBack, $fallback) {
          $target = $panelBack ?: $fallback;
          $sep = (str_contains($target, '?') ? '&' : '?');
          return \Kirby\Http\Response::redirect($target . $sep . 'exif=' . $status, 303);
        };

        $user = \kirby()->user();
        if (!$user) {
          return $goBack('err-auth');
        }

        $data = $request->data();
        $query = $request->query()->toArray();
        $pageId = (string)($data['page'] ?? '');
        $fileId = (string)($data['file'] ?? '');
        if ($pageId === '') $pageId = (string)($query['page'] ?? '');
        if ($fileId === '') $fileId = (string)($query['file'] ?? '');

        if ($pageId === '' || $fileId === '') {
          return $goBack('err-input');
        }

        $page = \page($pageId);
        if (!$page) {
          return $goBack('err-page');
        }

        $file = $page->file($fileId);
        if (!$file) {
          // Fallbacks für Panel-Parameter/abweichende ID-Formate
          $file = $page->files()->findBy('filename', $fileId)
            ?? $page->files()->findBy('id', $fileId)
            ?? $page->files()->findBy('uuid', $fileId);
        }
        if (!$file) {
          return $goBack('err-file');
        }

        if ($file->type() !== 'image') {
          return $goBack('err-type');
        }

        try {
          // Manueller Reimport: Geo/Adresse neu einlesen, ohne ExifImport-Toggle zu ändern.
          $stats = null;
          exifImportForPage($page, $file, true, false, $stats, true);
          if (($stats['changed'] ?? false) !== true) {
            return $goBack('err-noexif');
          }
          return $goBack('ok');
        } catch (\Throwable $e) {
          error_log('[Exif-Import] Fehler manual route: ' . $e->getMessage());
          return $goBack('err-run');
        }
      }
    ],
  ],
]);

/**
 * Re-Entry-Guard (einfach & global im Namespace)
 */
final class Guard {
  private static array $locks = [];
  public static function isLocked(string $key): bool { return !empty(self::$locks[$key]); }
  public static function lock(string $key): void     { self::$locks[$key] = true; }
  public static function unlock(string $key): void   { unset(self::$locks[$key]); }
}

\class_alias(__NAMESPACE__ . '\\Guard', 'hnz\\ExifImport\\Guard');

function maybeSyncStoryTitleAndSlugFromImport(Page $page): void
{
  if (exifOption('storyImport.enabled', true) !== true) {
    return;
  }

  $template = (string)exifOption('storyImport.template', 'story');
  if ($page->intendedTemplate()->name() !== $template) {
    return;
  }

  $desiredTitle = storyImportTitleFromPage($page);
  if ($desiredTitle === '') {
    return;
  }

  $currentTitle = trim((string)$page->title()->value());
  $slug = $page->slug();
  $normalizedTitle = Str::slug($currentTitle);
  $normalizedSlug = Str::slug($slug);
  $shouldRename = (
    $currentTitle === '' ||
    $normalizedTitle === '' ||
    $normalizedTitle === $normalizedSlug ||
    preg_match('/^(story[_-]?img|story[_-]?\d+|img[-_]?\d+)/i', $slug) === 1
  );

  if (!$shouldRename) {
    return;
  }

  $desiredSlugBase = Str::slug($desiredTitle);
  if ($desiredSlugBase === '') {
    return;
  }

  $targetSlug = uniqueSiblingSlug($page, $desiredSlugBase);

  \kirby()->impersonate('kirby');
  try {
    Guard::lock('page.update');
    $fresh = \page($page->id());
    if (!$fresh) {
      return;
    }

    if (trim((string)$fresh->title()->value()) !== $desiredTitle) {
      $fresh = $fresh->changeTitle($desiredTitle);
    }
    if ($fresh->slug() !== $targetSlug) {
      $fresh = $fresh->changeSlug($targetSlug);
    }
  } finally {
    Guard::unlock('page.update');
    \kirby()->impersonate(null);
  }
}

function storyImportTitleFromPage(Page $page): string
{
  $locationFieldKeys = (array)exifOption('storyImport.locationFields', ['LocationName', 'locationname']);
  $locationRaw = '';
  foreach ($locationFieldKeys as $fieldKey) {
    $value = trim((string)($page->content()->get((string)$fieldKey)?->value() ?? ''));
    if ($value !== '') {
      $locationRaw = $value;
      break;
    }
  }
  $location = $locationRaw;
  if (str_contains($locationRaw, ',')) {
    $location = trim((string)explode(',', $locationRaw)[0]);
  }
  if ($location === '') {
    $location = (string)exifOption('storyImport.unknownLocationLabel', 'Unbekannter Ort');
  }

  $dateFieldKeys = (array)exifOption('storyImport.dateFields', ['Datum', 'datum']);
  $dateRaw = '';
  foreach ($dateFieldKeys as $fieldKey) {
    $value = trim((string)($page->content()->get((string)$fieldKey)?->value() ?? ''));
    if ($value !== '') {
      $dateRaw = $value;
      break;
    }
  }
  if ($dateRaw === '') {
    return '';
  }

  $ts = strtotime($dateRaw);
  if ($ts === false) {
    return '';
  }

  return $location . ' am ' . formatGermanDateLong($ts);
}

function formatGermanDateLong(int $ts): string
{
  $day = (int)date('j', $ts);
  $month = (int)date('n', $ts);
  $year = (int)date('Y', $ts);
  $months = [
    1 => 'Januar',
    2 => 'Februar',
    3 => 'März',
    4 => 'April',
    5 => 'Mai',
    6 => 'Juni',
    7 => 'Juli',
    8 => 'August',
    9 => 'September',
    10 => 'Oktober',
    11 => 'November',
    12 => 'Dezember',
  ];
  $monthName = $months[$month] ?? date('F', $ts);
  return $day . '. ' . $monthName . ' ' . $year;
}

function uniqueSiblingSlug(Page $page, string $base): string
{
  $parent = $page->parent();
  if (!$parent) {
    return $base;
  }

  $slug = $base;
  $counter = 2;
  while (true) {
    $existing = $parent->childrenAndDrafts()->find($slug);
    if (!$existing || $existing->id() === $page->id()) {
      return $slug;
    }
    $slug = $base . '-' . $counter;
    $counter++;
  }
}

function firstPageFromArgs(...$args): ?Page
{
  foreach ($args as $arg) {
    if ($arg instanceof Page) {
      return $arg;
    }
    if ($arg instanceof File) {
      return $arg->page();
    }
    if (\is_object($arg)) {
      foreach (['page', 'model', 'target'] as $method) {
        if (\method_exists($arg, $method)) {
          $value = $arg->{$method}();
          if ($value instanceof Page) {
            return $value;
          }
          if ($value instanceof File) {
            return $value->page();
          }
        }
      }
      if (\method_exists($arg, 'arguments')) {
        $nested = (array)$arg->arguments();
        $page = firstPageFromArgs(...$nested);
        if ($page) {
          return $page;
        }
      }
    }
  }
  return null;
}

function firstFileFromArgs(...$args): ?File
{
  foreach ($args as $arg) {
    if ($arg instanceof File) {
      return $arg;
    }
    if (\is_object($arg)) {
      foreach (['file', 'target', 'model'] as $method) {
        if (\method_exists($arg, $method)) {
          $value = $arg->{$method}();
          if ($value instanceof File) {
            return $value;
          }
        }
      }
      if (\method_exists($arg, 'arguments')) {
        $nested = (array)$arg->arguments();
        $file = firstFileFromArgs(...$nested);
        if ($file) {
          return $file;
        }
      }
    }
  }
  return null;
}

/**
 * Liefert das Titelbild der Seite (mit Fallbacks):
 * titelbild/Titelbild -> heroImage -> erstes Bild
 */
function resolveTitleImage(Page $page): ?File
{
  $keys = (array)exifOption('titleImageFields', ['titelbild', 'Titelbild', 'heroImage']);
  foreach ($keys as $key) {
    $field = $page->content()->get($key);
    if ($field && $field->isNotEmpty()) {
      $value = (string)$field->value();
      if ($value !== '') {
        $file = $page->file($value);
        if ($file instanceof File && $file->type() === 'image') {
          return $file;
        }
      }
    }
  }

  $fallback = (string)exifOption('titleImageFallback', 'first-image');
  if ($fallback === 'none') {
    return null;
  }

  return $page->images()->first() ?: $page->files()->filterBy('type', 'image')->first();
}

function resolveFreshFileOnPage(Page $page, File $file): ?File
{
  $freshPage = \page($page->id());
  if (!$freshPage) {
    return null;
  }

  return $freshPage->file($file->filename())
    ?? $freshPage->files()->findBy('id', $file->id())
    ?? $freshPage->files()->findBy('uuid', $file->uuid()?->toString())
    ?? $freshPage->images()->findBy('filename', $file->filename());
}

/**
 * Stellt sicher, dass die Seite nicht auf 'default' steht.
 * Wird NUR im CREATE-Hook verwendet. Gibt eine frische Page zurück.
 */
function normalizeTemplate(Page $page): Page
{
  $p = \page($page->id()) ?? $page;
  if (!$p) {
    throw new \RuntimeException('Page could not be resolved in normalizeTemplate()');
  }
  $current  = $p->template()->name();
  $intended = $p->intendedTemplate()->name();

  error_log("[ForceTemplate] {$p->id()} current={$current} intended={$intended}");

  if ($current === 'default' && $intended !== 'default') {
    \kirby()->impersonate('kirby');
    try {
      $p = $p->changeTemplate($intended); // default.txt -> <intended>.txt
      error_log('[ForceTemplate] changed ' . $p->id() . " → {$intended}.txt");
    } finally {
      \kirby()->impersonate(null);
    }
  }

  return \page($p->id()) ?? $p; // frische Instanz, sonst Fallback auf vorhandenes Objekt
}

/**
 * Verarbeitet:
 * - Alle Images der Seite (wenn $focusFile null)
 * - Oder genau EIN File (wenn $focusFile gesetzt)
 * Spiegelt Seitenfelder NUR aus dem Titelbild (und nur, wenn leer).
 * Gibt die (ggf. aktualisierte) Page zurück.
 */
function exifImportForPage(
  Page $page,
  File $focusFile = null,
  bool $force = false,
  bool $setToggleFalse = true,
  ?array &$stats = null,
  bool $overwriteAllFileFields = false
): Page
{
  // Bilder holen (robust)
  if ($focusFile) {
    $images = [$focusFile];
  } else {
    $images = $page->images();
    if ($images->isEmpty()) {
      $images = $page->files()->filterBy('type', 'image');
    }
  }

  $count = \is_array($images) ? \count($images) : $images->count();
  error_log('[Exif-Import] Images gefunden: ' . $count . ' → ' . $page->id());
  if ($count === 0) {
    if ($setToggleFalse && $page->content()->has('ExifImport')) {
      \kirby()->impersonate('kirby');
      try {
        Guard::lock('page.update');
        try {
          \page($page->id())->update(['ExifImport' => 'false']);
        } catch (\Throwable $e) {
          error_log('[Exif-Import] Warnung: ExifImport-Toggle konnte nicht geschrieben werden: ' . $e->getMessage());
        }
      } finally {
        Guard::unlock('page.update');
        \kirby()->impersonate(null);
      }
    }
    $stats = [
      'changed' => false,
      'filesUpdated' => 0,
      'pageUpdated' => false,
      'imagesFound' => 0,
    ];
    return $page;
  }

  // Titelbild bestimmen
  $cover = resolveTitleImage($page);
  if (!$cover) {
    $cover = \is_array($images) ? $images[0] : $images->first();
  }

  $pageUpdate = [];
  $filesUpdated = 0;
  $pageUpdated = false;

  // Iteration über alle Bilder
  $iter = \is_array($images) ? $images : $images->data();
  foreach ($iter as $file) {
    if (!$file instanceof File) continue;

    $updates = buildUpdatesFromExif($file, $force, $overwriteAllFileFields);

    // --- Datei-Updates (.txt) ---
    if (!empty($updates['file'])) {
      \kirby()->impersonate('kirby');
      try {
        Guard::lock('file.update');
        $freshFile = resolveFreshFileOnPage($page, $file);
        if (!$freshFile) {
          throw new \RuntimeException('Frische Dateiinstanz konnte nicht geladen werden: ' . $file->filename());
        }

        $file = $freshFile->update($updates['file']); // neue File-Instanz übernehmen
        $filesUpdated++;
        error_log('[Exif-Import] Datei geschrieben: ' . $file->filename() . ' → ' . json_encode($updates['file']));
      } finally {
        Guard::unlock('file.update');
        \kirby()->impersonate(null);
      }
    } else {
      error_log('[Exif-Import] Keine Datei-Updates für: ' . $file->filename());
    }

    // --- Seitenfelder nur aus Cover spiegeln (und nur, wenn leer) ---
    if ($cover && $file->id() === $cover->id() && !empty($updates['page'])) {
      $map = (array)exifOption('pageMirrorMap', [
        'Latitude' => 'latitude',
        'Longitude' => 'longitude',
        'OSMLink' => 'osmlink',
        'LocationName' => 'locationname',
        'Strasse' => 'strasse',
        'Hausnummer' => 'hausnummer',
      ]);
      foreach ($updates['page'] as $K => $v) {
        if ($v === null || $v === '') continue;

        // Nie das Haupttext-Feld befüllen/überschreiben – Safety:
        if ($K === 'Text') {
          continue;
        }

        $lower = $map[$K] ?? lcfirst($K);
        $upperEmpty = \method_exists($page, $K)     ? $page->{$K}()->isEmpty()     : true;
        $lowerEmpty = \method_exists($page, $lower) ? $page->{$lower}()->isEmpty() : true;

        if ($upperEmpty && $lowerEmpty) {
          $pageUpdate[$K]     = $v;
          $pageUpdate[$lower] = $v;
        }
      }
      if (!empty($updates['page']['linkadresse'])) {
        $has = \method_exists($page, 'linkadresse') ? !$page->linkadresse()->isEmpty() : false;
        if (!$has) {
          $pageUpdate['linkadresse'] = $updates['page']['linkadresse'];
        }
      }
    }
  }

  // Toggle + Seitenfelder schreiben – mit FRISCHEM Page-Objekt
  if ($setToggleFalse && $page->content()->has('ExifImport')) {
    $pageUpdate['ExifImport'] = 'false';
  }
  if (!empty($pageUpdate)) {
    \kirby()->impersonate('kirby');
    try {
      Guard::lock('page.update');
      try {
        $fresh = \page($page->id());           // frische Instanz holen
        $fresh = $fresh->update($pageUpdate);  // neue Page-Instanz übernehmen

        error_log('[Exif-Import] Seite gespiegelt aus Titelbild: ' . json_encode($pageUpdate) . ' → ' . $fresh->id());
        $page = $fresh; // aktualisierte Seite weiterreichen
        $pageUpdated = true;
      } catch (\Throwable $e) {
        error_log('[Exif-Import] Warnung: Seiten-Mirror konnte nicht geschrieben werden: ' . $e->getMessage() . ' → ' . $page->id());
      }
    } finally {
      Guard::unlock('page.update');
      \kirby()->impersonate(null);
    }
  }

  $stats = [
    'changed' => ($filesUpdated > 0 || $pageUpdated),
    'filesUpdated' => $filesUpdated,
    'pageUpdated' => $pageUpdated,
    'imagesFound' => $count,
  ];

  return $page;
}

/**
 * EXIF/IPTC lesen → Updates bauen:
 * 'file' → Datei-`.txt`
 * 'page' → Spiegel-Werte für Seite (nur fürs Cover sinnvoll)
 * WICHTIG:
 *  - KEIN 'Text' im Page-Mirror (Hauptinhalt bleibt unberührt)
 *  - 'alt', 'caption' und 'locationname' an der Datei NUR setzen, wenn dort LEER
 */
function buildUpdatesFromExif(File $file, bool $force = false, bool $overwriteAllFileFields = false): array
{
  $path = $file->root();
  $exif = [];
  $exifFlat = [];
  $iptc = [];

  if (\function_exists('exif_read_data')) {
    $exif = @\exif_read_data($path, null, true) ?: [];
    $exifFlat = @\exif_read_data($path) ?: [];
  }
  $imgInfo = [];
  $dimensions = @\getimagesize($path, $imgInfo) ?: null;
  if (!empty($imgInfo['APP13'])) {
    $iptc = @\iptcparse($imgInfo['APP13']) ?: [];
  }

  // Alt/Caption (iOS „Bildbeschreibung“)
  $caption = null;
  if (!empty($exif['IFD0']['ImageDescription'])) {
    $caption = trim((string)$exif['IFD0']['ImageDescription']);
  } elseif (!empty($exifFlat['ImageDescription'])) {
    $caption = trim((string)$exifFlat['ImageDescription']);
  } elseif (!empty($exif['IFD0']['XPTitle'])) {
    $caption = exifDecodeUnicode($exif['IFD0']['XPTitle']);
  } elseif (!empty($exifFlat['XPTitle'])) {
    $caption = exifDecodeUnicode($exifFlat['XPTitle']);
  } elseif (!empty($exif['IFD0']['XPComment'])) {
    $caption = exifDecodeUnicode($exif['IFD0']['XPComment']);
  } elseif (!empty($exifFlat['XPComment'])) {
    $caption = exifDecodeUnicode($exifFlat['XPComment']);
  }
  if (!$caption && !empty($iptc['2#120'][0])) {
    $caption = trim($iptc['2#120'][0]);
  }
  if (!$caption) {
    $base = \pathinfo($file->filename(), PATHINFO_FILENAME);
    $caption = ucfirst(str_replace(['_', '-'], ' ', $base));
  }

  // --- vorhandene Datei-Felder prüfen (NICHT überschreiben, wenn vorhanden) ---
  $altCurrent         = readFileFieldValue($file, ['alt', 'Alt', 'alttext', 'AltText']);
  $hasAltAlready      = $altCurrent !== '';

  $captionCurrent     = readFileFieldValue($file, ['caption', 'Caption', 'bildunterschrift', 'Bildunterschrift']);
  $hasCaptionAlready  = $captionCurrent !== '';

  $locCurrent         = readFileFieldValue($file, ['locationname', 'LocationName']);
  $hasLocAlready      = $locCurrent !== '';
  $poiCurrent         = readFileFieldValue($file, ['poi', 'POI']);
  $districtCurrent    = readFileFieldValue($file, ['stadtteil', 'subregion', 'sub', 'Stadtteil']);
  $cityCurrent        = readFileFieldValue($file, ['stadt', 'city', 'City', 'Stadt']);

  // GPS
  $lat = $lon = null;
  $gps = [];
  if (!empty($exif['GPS']) && \is_array($exif['GPS'])) {
    $gps = $exif['GPS'];
  } else {
    $gps = [
      'GPSLatitude'     => $exifFlat['GPSLatitude']     ?? null,
      'GPSLatitudeRef'  => $exifFlat['GPSLatitudeRef']  ?? null,
      'GPSLongitude'    => $exifFlat['GPSLongitude']    ?? null,
      'GPSLongitudeRef' => $exifFlat['GPSLongitudeRef'] ?? null,
    ];
  }

  if (!empty($gps['GPSLatitude']) && !empty($gps['GPSLatitudeRef'])) {
    $lat = gps2Num(normalizeGpsCoord($gps['GPSLatitude']), (string)$gps['GPSLatitudeRef']);
  }
  if (!empty($gps['GPSLongitude']) && !empty($gps['GPSLongitudeRef'])) {
    $lon = gps2Num(normalizeGpsCoord($gps['GPSLongitude']), (string)$gps['GPSLongitudeRef']);
  }
  if ($lat === null || $lon === null) {
    try {
      $loc = $file->exif()->location();
      $lat = $lat ?? $loc->lat();
      $lon = $lon ?? $loc->lng();
    } catch (Throwable $e) {
      // ignore
    }
  }
  if ($lat === null || $lon === null) {
    [$xmpLat, $xmpLon] = readGpsFromXmp($path);
    $lat = $lat ?? $xmpLat;
    $lon = $lon ?? $xmpLon;
  }

  // Datei-Updates – alt/caption nur setzen, wenn leer
  $fileUpdate = [];
  if (!$hasAltAlready && $caption) {
    $fileUpdate['alt'] = $caption;
  }
  if (!$hasCaptionAlready && $caption) {
    $fileUpdate['caption'] = $caption;
  }

  $metaFields = buildTechnicalImageMetadata($file, $exif, $exifFlat, $dimensions);
  foreach ($metaFields as $key => $value) {
    if ($value === null || $value === '') {
      continue;
    }

    $currentValue = readFileFieldValue($file, [$key, ucfirst($key)]);
    if ($overwriteAllFileFields || $force || $currentValue === '') {
      $fileUpdate[$key] = $value;
    }
  }

  // Seiten-Mirror: KEIN 'Text'
  $pageMirror = [];

  if ($lat !== null && $lon !== null) {
    // Koordinaten/Links setzen (diese dürfen aktualisiert werden)
    $fileUpdate['latitude']   = (string)$lat;
    $fileUpdate['longitude']  = (string)$lon;

    $osmUrl = "https://www.openstreetmap.org/?mlat={$lat}&mlon={$lon}#map=18/{$lat}/{$lon}";
    $fileUpdate['osmlink']     = $osmUrl;
    $fileUpdate['linkadresse'] = 'true';

    $geo = reverseGeocode($lat, $lon);
    // Locationname/Strasse/Hausnummer nur setzen, wenn leer
    if (($overwriteAllFileFields || $force || !$hasLocAlready) && !empty($geo['short'])) {
      $fileUpdate['locationname'] = $geo['short'];
    }
    if (($overwriteAllFileFields || $force || $poiCurrent === '') && !empty($geo['poi'])) {
      $fileUpdate['poi'] = $geo['poi'];
    }
    if (($overwriteAllFileFields || $force || $districtCurrent === '') && !empty($geo['subregion'])) {
      $fileUpdate['stadtteil'] = $geo['subregion'];
      $fileUpdate['subregion'] = $geo['subregion'];
    }
    if (($overwriteAllFileFields || $force || $cityCurrent === '') && !empty($geo['city'])) {
      $fileUpdate['stadt'] = $geo['city'];
      $fileUpdate['city'] = $geo['city'];
    }

    $streetField = $file->content()->get('strasse');
    if (($overwriteAllFileFields || $force || !$streetField || $streetField->isEmpty()) && !empty($geo['road'])) {
      $fileUpdate['strasse'] = $geo['road'];
    }

    $hnoField = $file->content()->get('hausnummer');
    if (($overwriteAllFileFields || $force || !$hnoField || $hnoField->isEmpty()) && !empty($geo['house_number'])) {
      $fileUpdate['hausnummer'] = $geo['house_number'];
    }

    // Nur Geo/Link-Felder für die Seite spiegeln
    $pageMirror += [
      'Latitude'     => $lat,
      'Longitude'    => $lon,
      'OSMLink'      => $osmUrl,
      'LocationName' => $fileUpdate['locationname'] ?? ($hasLocAlready ? $locCurrent : null),
      'Strasse'      => $fileUpdate['strasse']      ?? (isset($streetField) && $streetField ? (string)$streetField : null),
      'Hausnummer'   => $fileUpdate['hausnummer']   ?? (isset($hnoField) && $hnoField ? (string)$hnoField : null),
      'linkadresse'  => 'true',
    ];
  } else {
    error_log('[Exif-Import] Keine GPS-Daten in EXIF/XMP für: ' . $file->filename());
  }

  return ['file' => $fileUpdate, 'page' => $pageMirror];
}

function buildTechnicalImageMetadata(File $file, array $exif, array $exifFlat, array|false|null $dimensions): array
{
  $captured = fileCapturedAt($file, $exif, $exifFlat);
  $camera = fileCameraLabel($exif, $exifFlat);
  $license = imageLicenseForFile($file);

  $resolution = null;
  if (\is_array($dimensions) && isset($dimensions[0], $dimensions[1])) {
    $resolution = (int)$dimensions[0] . 'x' . (int)$dimensions[1] . ' px';
  }

  $size = @filesize($file->root());
  $filesize = ($size !== false) ? formatBytes((int)$size) : null;

  return [
    'captured' => $captured,
    'camera' => $camera,
    'resolution' => $resolution,
    'filesize' => $filesize,
    'license' => $license,
    'copyright' => $license,
  ];
}

function fileCapturedAt(File $file, array $exif, array $exifFlat): ?string
{
  $candidates = [
    $exif['EXIF']['DateTimeOriginal'] ?? null,
    $exifFlat['DateTimeOriginal'] ?? null,
  ];

  foreach ($candidates as $value) {
    $normalized = normalizeExifDateString($value);
    if ($normalized !== null) {
      return $normalized;
    }
  }

  return null;
}

function fileCameraLabel(array $exif, array $exifFlat): ?string
{
  $make = trim((string)($exif['IFD0']['Make'] ?? $exifFlat['Make'] ?? ''));
  $model = trim((string)($exif['IFD0']['Model'] ?? $exifFlat['Model'] ?? ''));
  $camera = trim($make . ' ' . $model);

  return $camera !== '' ? preg_replace('/\s+/', ' ', $camera) : null;
}

function imageLicenseForFile(File $file): ?string
{
  $page = $file->page();
  if (!$page) {
    return null;
  }

  $template = $page->intendedTemplate()->name();
  $sourcePlatformField = (string)exifOption('license.sourcePlatformField', 'Ursprungsplattform');
  $pixelfedField = (string)exifOption('license.pixelfedField', 'PixelfedLink');
  $storyTemplates = (array)exifOption('license.storyTemplates', ['story']);
  $pixelfedPlatformLabels = (array)exifOption('license.pixelfedPlatformLabels', ['Pixelfed']);
  $licenseValue = trim((string)exifOption('license.value', 'CC BY-SA 4.0'));
  $sourcePlatform = trim((string)($page->content()->get($sourcePlatformField)?->value() ?? ''));
  $hasPixelfedLink = $page->content()->get($pixelfedField)?->isNotEmpty() === true;

  foreach ($pixelfedPlatformLabels as $label) {
    if (strcasecmp($sourcePlatform, (string)$label) === 0) {
      return $licenseValue !== '' ? $licenseValue : null;
    }
  }

  if (in_array($template, $storyTemplates, true) || $hasPixelfedLink) {
    return $licenseValue !== '' ? $licenseValue : null;
  }

  return null;
}

function normalizeExifDateString(mixed $value): ?string
{
  if ($value === null) {
    return null;
  }

  $raw = trim((string)$value);
  if ($raw === '') {
    return null;
  }

  $normalized = preg_replace('/^(\d{4}):(\d{2}):(\d{2})/', '$1-$2-$3', $raw);
  $tz = new \DateTimeZone(date_default_timezone_get() ?: 'Europe/Berlin');
  $formats = [
    'Y-m-d H:i:sP',
    'Y-m-d H:i:s',
    'Y-m-d\TH:i:sP',
    'Y-m-d\TH:i:s',
    'Y-m-d',
  ];

  foreach ($formats as $format) {
    $dt = \DateTimeImmutable::createFromFormat($format, (string)$normalized, $tz);
    if ($dt instanceof \DateTimeImmutable) {
      return $dt->format('Y-m-d H:i:s');
    }
  }

  $timestamp = strtotime((string)$normalized);
  if ($timestamp === false) {
    return null;
  }

  return date('Y-m-d H:i:s', $timestamp);
}

function formatBytes(int $bytes): string
{
  $units = ['B', 'KB', 'MB', 'GB', 'TB'];
  $size = (float)$bytes;
  $unitIndex = 0;

  while ($size >= 1024 && $unitIndex < count($units) - 1) {
    $size /= 1024;
    $unitIndex++;
  }

  $precision = $unitIndex === 0 ? 0 : ($size >= 10 ? 1 : 2);
  $formatted = number_format($size, $precision, '.', '');
  $formatted = rtrim(rtrim($formatted, '0'), '.');

  return $formatted . ' ' . $units[$unitIndex];
}

function fileHasGpsMetadata(File $file): bool
{
  $path = $file->root();
  if (!$path || !is_file($path)) {
    return false;
  }

  $exif = [];
  $exifFlat = [];
  if (\function_exists('exif_read_data')) {
    $exif = @\exif_read_data($path, null, true) ?: [];
    $exifFlat = @\exif_read_data($path) ?: [];
  }

  $gps = [];
  if (!empty($exif['GPS']) && \is_array($exif['GPS'])) {
    $gps = $exif['GPS'];
  } else {
    $gps = [
      'GPSLatitude'     => $exifFlat['GPSLatitude'] ?? null,
      'GPSLatitudeRef'  => $exifFlat['GPSLatitudeRef'] ?? null,
      'GPSLongitude'    => $exifFlat['GPSLongitude'] ?? null,
      'GPSLongitudeRef' => $exifFlat['GPSLongitudeRef'] ?? null,
    ];
  }
  if (!empty($gps['GPSLatitude']) && !empty($gps['GPSLongitude'])) {
    return true;
  }

  try {
    $loc = $file->exif()->location();
    if ($loc->lat() !== null && $loc->lng() !== null) {
      return true;
    }
  } catch (\Throwable $e) {
    // ignore
  }

  [$xmpLat, $xmpLon] = readGpsFromXmp($path);
  return ($xmpLat !== null && $xmpLon !== null);
}

function readFileFieldValue(File $file, array $keys): string
{
  $content = $file->content();
  if (!$content) {
    return '';
  }

  foreach ($keys as $k) {
    $field = $content->get($k);
    if ($field && $field->isNotEmpty()) {
      return trim((string)$field->value());
    }
  }

  $all = $content->toArray();
  $lower = array_change_key_case($all, CASE_LOWER);
  foreach ($keys as $k) {
    $lk = mb_strtolower($k);
    if (array_key_exists($lk, $lower)) {
      $v = trim((string)$lower[$lk]);
      if ($v !== '') {
        return $v;
      }
    }
  }

  return '';
}

// ---------- Helpers ----------
function normalizeGpsCoord($coord): array
{
  if (\is_array($coord)) {
    // Bereits [deg, min, sec] oder ähnlich
    return \array_values($coord);
  }

  $raw = trim((string)$coord);
  if ($raw === '') {
    return [];
  }

  // Manche EXIF-Quellen liefern "51/1, 30/1, 1234/100"
  if (str_contains($raw, ',')) {
    $parts = array_map('trim', explode(',', $raw));
    return array_values(array_filter($parts, fn ($p) => $p !== ''));
  }

  // Fallback: "51/1 30/1 1234/100"
  if (preg_match('/\s+/', $raw)) {
    $parts = preg_split('/\s+/', $raw) ?: [];
    return array_values(array_filter($parts, fn ($p) => $p !== ''));
  }

  return [$raw];
}

function gps2Num($coord, $hemisphere) {
  $degrees = \count($coord) > 0 ? frac2Num($coord[0]) : 0;
  $minutes = \count($coord) > 1 ? frac2Num($coord[1]) : 0;
  $seconds = \count($coord) > 2 ? frac2Num($coord[2]) : 0;
  $flip = ($hemisphere === 'S' || $hemisphere === 'W') ? -1 : 1;
  return $flip * ($degrees + ($minutes / 60) + ($seconds / 3600));
}

function frac2Num($str) {
  if (\is_array($str)) {
    if (isset($str['num'], $str['den'])) {
      $den = (float)$str['den'];
      return $den != 0.0 ? ((float)$str['num'] / $den) : 0.0;
    }
    $values = array_values($str);
    if (\count($values) >= 2 && is_numeric($values[0]) && is_numeric($values[1])) {
      $den = (float)$values[1];
      return $den != 0.0 ? ((float)$values[0] / $den) : 0.0;
    }
    if (\count($values) >= 1) {
      return (float)$values[0];
    }
    return 0.0;
  }

  $parts = \explode('/', (string)$str);
  if (\count($parts) <= 1) return (float)$str;
  $den = (float)$parts[1];
  return $den != 0.0 ? ((float)$parts[0] / $den) : 0.0;
}

function exifDecodeUnicode($raw) {
  if (!$raw) return null;
  if (\is_array($raw)) $raw = \implode('', \array_map('chr', $raw));
  if (\preg_match('/^(\d{1,3}\s+)+\d{1,3}$/', trim($raw))) {
    $bytes = \array_map('intval', \preg_split('/\s+/', trim($raw)));
    $raw = '';
    foreach ($bytes as $b) $raw .= \chr($b);
  }
  $s = @\mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE');
  return $s ? trim($s, "\x00\t\n\r ") : null;
}

function readGpsFromXmp(string $path): array
{
  $content = @file_get_contents($path);
  if (!$content) {
    return [null, null];
  }

  $start = strpos($content, '<x:xmpmeta');
  if ($start === false) {
    $start = strpos($content, '<rdf:RDF');
  }
  if ($start === false) {
    return [null, null];
  }

  $xmp = substr($content, $start, 250000);
  if ($xmp === false || $xmp === '') {
    return [null, null];
  }

  $latRaw = null;
  $lonRaw = null;

  if (preg_match('/exif:GPSLatitude=["\']([^"\']+)["\']/', $xmp, $m)) {
    $latRaw = trim($m[1]);
  }
  if (preg_match('/exif:GPSLongitude=["\']([^"\']+)["\']/', $xmp, $m)) {
    $lonRaw = trim($m[1]);
  }

  if ($latRaw === null || $lonRaw === null) {
    return [null, null];
  }

  return [parseXmpGpsCoordinate($latRaw), parseXmpGpsCoordinate($lonRaw)];
}

function parseXmpGpsCoordinate(string $raw): ?float
{
  $v = trim($raw);
  if ($v === '') {
    return null;
  }

  $sign = 1.0;
  if (preg_match('/[SW]$/i', $v)) {
    $sign = -1.0;
  }
  $v = preg_replace('/[NSEW]$/i', '', $v);
  $v = trim((string)$v);

  // Decimal notation, e.g. "51.517844"
  if (preg_match('/^-?\d+(?:\.\d+)?$/', $v)) {
    return $sign * (float)$v;
  }

  // DMS notation, e.g. "51, 31.0704"
  $parts = preg_split('/[,\s]+/', $v) ?: [];
  $parts = array_values(array_filter($parts, fn ($p) => $p !== ''));
  if (count($parts) === 0) {
    return null;
  }

  $deg = (float)($parts[0] ?? 0);
  $min = (float)($parts[1] ?? 0);
  $sec = (float)($parts[2] ?? 0);
  $abs = abs($deg) + ($min / 60.0) + ($sec / 3600.0);
  return $sign * ($deg < 0 ? -$abs : $abs);
}

/**
 * Reverse Geocoding via Nominatim (OSM)
 */
function reverseGeocode(float $lat, float $lon): array {
  $url = "https://nominatim.openstreetmap.org/reverse?format=json&lat={$lat}&lon={$lon}&zoom=18&addressdetails=1&namedetails=1";
  $opts = [
    'http' => [
      'header' => "User-Agent: KirbyExifImport/1.0\r\n",
      'timeout' => 5
    ]
  ];
  $ctx  = \stream_context_create($opts);
  $json = @\file_get_contents($url, false, $ctx);
  if (!$json) return ['short' => null];

  $data        = \json_decode($json, true);
  $namedetails = $data['namedetails'] ?? [];
  $addr        = $data['address'] ?? [];

  $poi = $namedetails['name'] ?? ($addr['building'] ?? ($addr['amenity'] ?? ''));
  $subregion = '';
  foreach (['suburb','neighbourhood','quarter','village','hamlet'] as $k) {
    if (!empty($addr[$k])) { $subregion = $addr[$k]; break; }
  }
  $city = '';
  foreach (['city','town','municipality'] as $k) {
    if (!empty($addr[$k])) { $city = $addr[$k]; break; }
  }
  $shortParts = \array_filter([$poi, $subregion, $city]);
  $short = !empty($shortParts) ? \implode(', ', $shortParts) : ($data['display_name'] ?? null);

  return [
    'poi'          => $poi ? trim($poi) : null,
    'subregion'    => $subregion ? trim($subregion) : null,
    'city'         => $city ? trim($city) : null,
    'short'        => $short ? trim($short) : null,
    'road'         => $addr['road']         ?? null,
    'house_number' => $addr['house_number'] ?? null,
  ];
}
