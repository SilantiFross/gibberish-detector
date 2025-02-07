<?php

namespace SilantiFross\GibberishDetector;

use InvalidArgumentException;

/**
 * Tests text content for gibberish input such as
 * tapoktrpasawe
 * qweasd qwa as
 * aıe qwo ıak kqw
 * qwe qwe qwe a
 *
 * @link http://stackoverflow.com/questions/6297991/is-there-any-way-to-detect-strings-like-putjbtghguhjjjanika
 * @link https://github.com/rrenaud/Gibberish-Detector
 * @link http://en.wikipedia.org/wiki/Markov_chain
 * @param string $text The text to check.
 * @param array $options
 * @return mixed
 * @author Oliver Lillie
 * @author Silantsi Rudnitski update for php 8.0
 * @author Rob Renaud Python implementation
 */
class Gibberish
{
	protected static string $_accepted_characters = 'abcdefghijklmnopqrstuvwxyz ';

	/**
	 * Analyzes text using a trained library and determines if it meets the threshold criteria.
	 *
	 * @param string $text The input text to analyze
	 * @param string $libraryPath Path to the serialized library file
	 * @param bool $raw Whether to return raw probability value
	 * @return float|bool Probability value if $raw=true, boolean result otherwise
	 * @throws InvalidArgumentException For invalid library file or structure
	 */
	public static function test(string $text, string $libraryPath, bool $raw = false): float|bool
	{
		$library = self::loadLibrary($libraryPath);
		self::validateLibrary($library);

		$probability = self::calculateTransitionProbability($text, $library['matrix']);

		return $raw ? $probability : $probability <= $library['threshold'];
	}

	/**
	 * Loads and validates the library from file.
	 */
	private static function loadLibrary(string $libraryPath): array
	{
		if (!file_exists($libraryPath)) {
			throw new InvalidArgumentException("Library file not found: {$libraryPath}");
		}

		$contents = file_get_contents($libraryPath);
		if ($contents === false) {
			throw new InvalidArgumentException("Failed to read library file: {$libraryPath}");
		}

		$library = unserialize($contents);
		if ($library === false) {
			throw new InvalidArgumentException("Corrupted library file: {$libraryPath}");
		}

		return $library;
	}

	/**
	 * Validates the library structure.
	 */
	private static function validateLibrary(array $library): void
	{
		$requiredKeys = ['matrix', 'threshold'];

		if (count(array_intersect($requiredKeys, array_keys($library))) !== count($requiredKeys)) {
			throw new InvalidArgumentException("Invalid library structure - missing required components");
		}
	}

	/**
	 * Calculates transition probability.
	 */
	private static function calculateTransitionProbability(string $text, array $matrix): float
	{
		$log_prob = 1.0;
		$transition_ct = 0;

		$pos = array_flip(str_split(self::$_accepted_characters));
		$filtered_line = str_split(self::_normalise($text));
		$a = false;
		foreach ($filtered_line as $b) {
			if ($a !== false) {
				$log_prob += $matrix[$pos[$a]][$pos[$b]];
				$transition_ct += 1;
			}
			$a = $b;
		}
		# The exponentiation translates from log probs to probs.
		return exp($log_prob / max($transition_ct, 1));
	}

	protected static function _normalise(string $line): array|string|null
	{
//      Return only the subset of chars from accepted_chars.
//      This helps keep the  model relatively small by ignoring punctuation,
//      infrequenty symbols, etc.
		return preg_replace('/[^a-z\ ]/', '', strtolower($line));
	}

	public static function train(string $big_text_file, string $good_text_file, string $bad_text_file, string $lib_path): bool
	{
		if (!file_exists($big_text_file)) {
			throw new InvalidArgumentException("Specified big text file not found");
		}
		if (!file_exists($good_text_file)) {
			throw new InvalidArgumentException("Specified goof text file not found");
		}
		if (!file_exists($bad_text_file)) {
			throw new InvalidArgumentException("Specified bad text file not found");
		}

		$pos = array_flip(str_split(self::$_accepted_characters));

//      Assume we have seen 10 of each character pair.  This acts as a kind of
//      prior or smoothing factor.  This way, if we see a character transition
//      live that we've never observed in the past, we won't assume the entire
//      string has 0 probability.
		$log_prob_matrix = [];
		$range = range(0, count($pos) - 1);
		foreach ($range as $index1) {
			$array = [];
			foreach ($range as $index2) {
				$array[$index2] = 10;
			}
			$log_prob_matrix[$index1] = $array;
		}

//      Count transitions from big text file, taken
//      from http://norvig.com/spell-correct.html
		$lines = file($big_text_file);
		foreach ($lines as $line) {
//          Return all n grams from l after normalizing
			$filtered_line = str_split(self::_normalise($line));
			$a = false;
			foreach ($filtered_line as $b) {
				if ($a !== false) {
					$log_prob_matrix[$pos[$a]][$pos[$b]] += 1;
				}
				$a = $b;
			}
		}
		unset($lines, $filtered_line);

//      Normalize the counts so that they become log probabilities.
//      We use log probabilities rather than straight probabilities to avoid
//      numeric underflow issues with long texts.
//      This contains a justification:
//      http://squarecog.wordpress.com/2009/01/10/dealing-with-underflow-in-joint-probability-calculations/
		foreach ($log_prob_matrix as $i => $row) {
			$s = (float)array_sum($row);
			foreach ($row as $k => $j) {
				$log_prob_matrix[$i][$k] = log($j / $s);
			}
		}

//      Find the probability of generating a few arbitrarily choosen good and
//      bad phrases.
		$good_lines = file($good_text_file);
		$good_probs = [];
		foreach ($good_lines as $line) {
			$good_probs[] = self::calculateTransitionProbability($line, $log_prob_matrix);
		}
		$bad_lines = file($bad_text_file);
		$bad_probs = [];
		foreach ($bad_lines as $line) {
			$bad_probs[] = self::calculateTransitionProbability($line, $log_prob_matrix);
		}
//      Assert that we actually are capable of detecting the junk.
		$min_good_probs = min($good_probs);
		$max_bad_probs = max($bad_probs);

		if ($min_good_probs <= $max_bad_probs) {
			return false;
		}

//      And pick a threshold halfway between the worst good and best bad inputs.
		$threshold = ($min_good_probs + $max_bad_probs) / 2;

//      save matrix
		return file_put_contents($lib_path, serialize([
			'matrix' => $log_prob_matrix,
			'threshold' => $threshold,
		])) > 0;
	}

}