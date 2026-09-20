<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Homework\HomeworkRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Wie viele Personen haben abgegeben - nicht wie viele Einreichungen.
 *
 * Gezaehlt wurde vorher ueber student_pseudonym. Das ist je Einreichung neu
 * gewuerfelt ('Student_' . bin2hex(random_bytes(4))), COUNT(DISTINCT ...)
 * war damit dasselbe wie COUNT(*). Wo "12 / 25 abgegeben" stand, zaehlten in
 * Wirklichkeit die Einreichungen - und bis zu drei davon kommen von
 * derselben Person.
 */
final class HomeworkZaehlungTest extends TestCase
{
    private PDO $conn;

    protected function setUp(): void
    {
        $this->conn = new PDO('sqlite::memory:');
        $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->conn->exec('CREATE TABLE homework_submissions (
            id INTEGER PRIMARY KEY, assignment_id INTEGER, student_name TEXT,
            student_key TEXT, student_pseudonym TEXT, attempt_no INTEGER)');
    }

    private function abgabe(int $assignmentId, string $name, int $versuch): void
    {
        $key = \App\Homework\Gamification::studentKey($name);

        $stmt = $this->conn->prepare(
            'INSERT INTO homework_submissions
                (assignment_id, student_name, student_key, student_pseudonym, attempt_no)
             VALUES (?, ?, ?, ?, ?)'
        );
        // Genau wie im Betrieb: je Einreichung ein neues Pseudonym.
        $stmt->execute([$assignmentId, $name, $key, 'Student_' . bin2hex(random_bytes(4)), $versuch]);
    }

    public function testMehrfachabgabeZaehltEinmal(): void
    {
        $this->abgabe(1, 'Mia Schuster', 1);
        $this->abgabe(1, 'Mia Schuster', 2);
        $this->abgabe(1, 'Mia Schuster', 3);
        $this->abgabe(1, 'Jonas Weber', 1);

        $repository = new HomeworkRepository($this->conn);

        self::assertSame(2, $repository->distinctStudentCount(1), 'zwei Personen, vier Einreichungen');
        self::assertSame(
            4,
            (int)$this->conn->query('SELECT COUNT(*) FROM homework_submissions WHERE assignment_id = 1')->fetchColumn(),
            'Voraussetzung: es sind wirklich vier Einreichungen'
        );
    }

    /**
     * Die alte Zaehlung haette hier 4 geliefert - mehr Abgaben als Koepfe.
     */
    public function testAlteZaehlungWaereFalschGewesen(): void
    {
        $this->abgabe(1, 'Mia Schuster', 1);
        $this->abgabe(1, 'Mia Schuster', 2);
        $this->abgabe(1, 'Jonas Weber', 1);
        $this->abgabe(1, 'Jonas Weber', 2);

        $alt = (int)$this->conn->query(
            'SELECT COUNT(DISTINCT student_pseudonym) FROM homework_submissions WHERE assignment_id = 1'
        )->fetchColumn();

        self::assertSame(4, $alt, 'das Pseudonym ist je Einreichung verschieden');
        self::assertSame(2, (new HomeworkRepository($this->conn))->distinctStudentCount(1));
    }

    public function testSchreibweiseDesNamensAendertNichts(): void
    {
        $this->abgabe(1, 'Mia Schuster', 1);
        $this->abgabe(1, '  mia   schuster ', 2);
        $this->abgabe(1, 'Mía Schuster', 3);

        self::assertSame(
            2,
            (new HomeworkRepository($this->conn))->distinctStudentCount(1),
            'Gross- und Kleinschreibung und Leerraum werden vereinheitlicht, ein Akzent nicht'
        );
    }

    public function testAndereAufgabeZaehltNichtMit(): void
    {
        $this->abgabe(1, 'Mia Schuster', 1);
        $this->abgabe(2, 'Jonas Weber', 1);

        self::assertSame(1, (new HomeworkRepository($this->conn))->distinctStudentCount(1));
        self::assertSame(0, (new HomeworkRepository($this->conn))->distinctStudentCount(3));
    }
}
