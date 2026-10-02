<?php

declare(strict_types=1);

namespace LiquidMonitorConnector\Telemetry;

/**
 * Napojí recorder na `StORM\Connection::setQueryObserver()` (StORM 2.1+).
 *
 * Connector na StORM nezávisí: starší StORM bez observeru se tiše přeskočí.
 */
final class StormBridge
{
	public const TYPE = 'sql';

	/**
	 * SQL delší než tohle jde do recorderu jen jako začátek a konec ({@see key()}).
	 *
	 * Dotaz se jako klíč v requestu normalizuje a hashuje celý, takže režie roste s délkou textu. StORM umí
	 * poskládat dotaz o stovkách kB (produktový SELECT v ABEL / Ráj tiskáren má ~183 kB, z toho ~153 kB seznam sloupců
	 * s cenovými výrazy) a jedna stránka jich pošle desítky — na produkci to dělalo 4–8 ms na request.
	 */
	public const KEY_MAX_BYTES = 4096;

	/** Začátek dotazu v klíči — co a z čeho se čte. */
	public const KEY_HEAD_BYTES = 1024;

	/** Konec dotazu v klíči — WHERE, GROUP BY, ORDER BY, LIMIT, podle kterých se dotazy liší. */
	public const KEY_TAIL_BYTES = 3072;

	public static function attach(object $connection, TelemetryConfig $config): void
	{
		if (!$config->storm || !$config->isActiveForCurrentSapi() || !\method_exists($connection, 'setQueryObserver')) {
			return;
		}

		Recorder::setKeyNormalizer(self::TYPE, self::normalizeSql(...));
		$connection->setQueryObserver(static function (string $sql, int $durationNs, bool $error): void {
			Recorder::record(self::TYPE, self::key($sql), $durationNs, $error);
		});
	}

	/**
	 * Klíč operace pro dotaz: kratší SQL beze změny, delší jen jako začátek + `…` + konec.
	 * Dotazy, které se liší jen uprostřed (seznamem sloupců), tím splynou — v telemetrii jde o tvar
	 * dotazu, ne o jeho přesný text. Konec se odměřuje od konce řetězce, takže varianty s jinou délkou
	 * parametrů (`:__var9` vs `:__var10`) mohou dát dva klíče; přesné sloučení by znamenalo normalizovat
	 * celý text, a to je právě ta drahá část.
	 */
	public static function key(string $sql): string
	{
		$length = \mb_strlen($sql, '8bit');

		if ($length <= self::KEY_MAX_BYTES) {
			return $sql;
		}

		// mb_strcut řeže po bajtech, ale nerozdělí znak UTF-8 (datagram je JSON).
		return \mb_strcut($sql, 0, self::KEY_HEAD_BYTES, 'UTF-8') . ' … ' . \mb_strcut($sql, $length - self::KEY_TAIL_BYTES, self::KEY_TAIL_BYTES, 'UTF-8');
	}

	/**
	 * Literály a pojmenované parametry StORM (`:__var123`) → `?`, seznam `(?, ?, …)` → `(?+)`. StORM část
	 * hodnot vkládá přímo do SQL (`IN ('uuid1', 'uuid2')`) a parametry čísluje průběžně v rámci requestu,
	 * takže jeden dotaz by jinak měl stovky variant.
	 */
	public static function normalizeSql(string $sql): string
	{
		return \preg_replace(
			['/:__var\d+\b/', '/\'(?:[^\'\\\\]|\\\\.)*\'/', '/"(?:[^"\\\\]|\\\\.)*"/', '/\b\d+(?:\.\d+)?\b/', '/\(\s*\?(?:\s*,\s*\?)+\s*\)/'],
			['?', '?', '?', '?', '(?+)'],
			$sql,
		) ?? $sql;
	}
}
