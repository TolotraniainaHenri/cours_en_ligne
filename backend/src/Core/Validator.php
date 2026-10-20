<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Validation des données reçues : chaque champ est vérifié (type, longueur, valeurs permises)
 * et seules les valeurs validées sont renvoyées par validate().
 */
final class Validator
{
    /** @var array<string,string> */
    private array $errors = [];
    /** @var array<string,mixed> */
    private array $clean = [];

    public function __construct(private readonly array $input)
    {
    }

    /** Retourne true (et note l'erreur) si le champ est absent ou vide. */
    private function isMissing(string $field, bool $required): bool
    {
        $v = $this->input[$field] ?? null;
        if ($v === null || $v === '') {
            if ($required) {
                $this->errors[$field] = 'Champ obligatoire.';
            } else {
                $this->clean[$field] = null;
            }
            return true;
        }
        return false;
    }

    public function text(string $field, int $min, int $max, bool $required = true): static
    {
        if ($this->isMissing($field, $required)) {
            return $this;
        }
        $v = $this->input[$field];
        if (!is_string($v)) {
            $this->errors[$field] = 'Texte attendu.';
            return $this;
        }
        $v = trim($v);
        $len = mb_strlen($v);
        if ($len < $min || $len > $max) {
            $this->errors[$field] = "Entre $min et $max caractères.";
        } else {
            $this->clean[$field] = $v;
        }
        return $this;
    }

    /** Comme text() mais sans trim (mots de passe). */
    public function secret(string $field, int $min, int $max): static
    {
        if ($this->isMissing($field, true)) {
            return $this;
        }
        $v = $this->input[$field];
        if (!is_string($v) || strlen($v) < $min || strlen($v) > $max) {
            $this->errors[$field] = "Entre $min et $max caractères.";
        } else {
            $this->clean[$field] = $v;
        }
        return $this;
    }

    public function email(string $field): static
    {
        if ($this->isMissing($field, true)) {
            return $this;
        }
        $v = $this->input[$field];
        $v = is_string($v) ? strtolower(trim($v)) : '';
        if ($v === '' || strlen($v) > 190 || filter_var($v, FILTER_VALIDATE_EMAIL) === false) {
            $this->errors[$field] = 'Adresse email invalide.';
        } else {
            $this->clean[$field] = $v;
        }
        return $this;
    }

    /** Politique de mot de passe : 10 à 72 caractères, au moins une lettre et un chiffre. */
    public function password(string $field): static
    {
        $this->secret($field, 10, 72);
        if (isset($this->clean[$field])) {
            $v = $this->clean[$field];
            if (!preg_match('/\p{L}/u', $v) || !preg_match('/\d/', $v)) {
                unset($this->clean[$field]);
                $this->errors[$field] = 'Le mot de passe doit contenir au moins une lettre et un chiffre.';
            }
        }
        return $this;
    }

    public function integer(string $field, int $min, int $max, bool $required = true): static
    {
        if ($this->isMissing($field, $required)) {
            return $this;
        }
        $v = $this->input[$field];
        if (is_string($v) && preg_match('/^-?\d+$/', $v)) {
            $v = (int) $v;
        }
        if (!is_int($v) || $v < $min || $v > $max) {
            $this->errors[$field] = "Entier entre $min et $max attendu.";
        } else {
            $this->clean[$field] = $v;
        }
        return $this;
    }

    /** @param list<string> $allowed */
    public function choice(string $field, array $allowed, bool $required = true): static
    {
        if ($this->isMissing($field, $required)) {
            return $this;
        }
        $v = $this->input[$field];
        if (!is_string($v) || !in_array($v, $allowed, true)) {
            $this->errors[$field] = 'Valeur permise : ' . implode(', ', $allowed) . '.';
        } else {
            $this->clean[$field] = $v;
        }
        return $this;
    }

    public function url(string $field, bool $required = false): static
    {
        if ($this->isMissing($field, $required)) {
            return $this;
        }
        $v = $this->input[$field];
        $scheme = is_string($v) ? strtolower((string) parse_url($v, PHP_URL_SCHEME)) : '';
        if (!is_string($v) || strlen($v) > 500 || filter_var($v, FILTER_VALIDATE_URL) === false
            || !in_array($scheme, ['http', 'https'], true)) {
            $this->errors[$field] = 'URL http(s) valide attendue.';
        } else {
            $this->clean[$field] = $v;
        }
        return $this;
    }

    public function addError(string $field, string $message): static
    {
        $this->errors[$field] = $message;
        return $this;
    }

    /** @return array<string,mixed> valeurs validées ; lève une HttpException 422 s'il y a des erreurs */
    public function validate(): array
    {
        if ($this->errors !== []) {
            throw new HttpException(422, 'Données invalides.', $this->errors);
        }
        return $this->clean;
    }
}
