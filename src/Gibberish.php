<?php

namespace FoxORM\GibberishDetector;

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
 * @author Rob Renaud Python implementation
 */
class Gibberish
{
	protected static string $_accepted_characters = 'abcdefghijklmnopqrstuvwxyz ';

	public static function test(string $text, string $lib_path, $raw = false): float|bool
	{
		if (!file_exists($lib_path)) {
			throw new InvalidArgumentException("Library file not found: {$lib_path}");
		}
		$trained_library = unserialize(file_get_contents($lib_path));

		if (!is_array($trained_library) || !isset($trained_library['matrix'], $trained_library['threshold'])) {
			throw new InvalidArgumentException("Invalid or corrupted library file: {$lib_path}");
		}

		$value = self::_averageTransitionProbability($text, $trained_library['matrix']);
		if ($raw === true) {
			return $value;
		}

		if ($value <= $trained_library['threshold']) {
			return true;
		}

		return false;
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
		$errors = [];

		if (is_file($big_text_file) === false) {
			$errors[] = 'specified big_text_file does not exist';
		}
		if (is_file($good_text_file) === false) {
			$errors[] = 'specified good_text_file does not exist';
		}
		if (is_file($bad_text_file) === false) {
			$errors[] = 'specified bad_text_file does not exist';
		}

		if ($errors) {
			echo 'File Errors(s):<br>';
			echo implode('<br>', $errors) . '<br><br>';
			return false;
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
			$good_probs[] = self::_averageTransitionProbability($line, $log_prob_matrix);
		}
		$bad_lines = file($bad_text_file);
		$bad_probs = [];
		foreach ($bad_lines as $line) {
			$bad_probs[] = self::_averageTransitionProbability($line, $log_prob_matrix);
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

	public static function _averageTransitionProbability(string $line, array $log_prob_matrix): float
	{
//      Return the average transition prob from line through log_prob_mat.
		$log_prob = 1.0;
		$transition_ct = 0;

		$pos = array_flip(str_split(self::$_accepted_characters));
		$filtered_line = str_split(self::_normalise($line));
		$a = false;
		foreach ($filtered_line as $b) {
			if ($a !== false) {
				$log_prob += $log_prob_matrix[$pos[$a]][$pos[$b]];
				$transition_ct += 1;
			}
			$a = $b;
		}
		# The exponentiation translates from log probs to probs.
		return exp($log_prob / max($transition_ct, 1));
	}
}