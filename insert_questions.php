<?php
// insert_questions.php – Run this ONCE to populate your database with questions
// After successful insertion, DELETE this file or move it outside public access.

// Database configuration (adjust to your XAMPP settings)
$host = 'localhost';
$dbname = 'ca_portal';      // change to your actual database name
$username = 'root';          // XAMPP default user
$password = '';              // XAMPP default password (empty)

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "Connected to database successfully.<br><br>";
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// ---------- Define questions for each course ----------
// Each course has an array of 50 questions.
// Each question = [question_text, option_a, option_b, option_c, option_d, correct_answer]

$courses = [
    'CSC101' => [
        ['What does CPU stand for?', 'Central Processing Unit', 'Computer Personal Unit', 'Central Program Utility', 'Core Processing Utility', 'A'],
        ['Which of the following is a volatile memory?', 'ROM', 'Hard Disk', 'RAM', 'SSD', 'C'],
        ['Who is known as the father of the computer?', 'Charles Babbage', 'Alan Turing', 'Bill Gates', 'Steve Jobs', 'A'],
        // ----- ADD THE REMAINING 47 QUESTIONS HERE (see note below) -----
        // For brevity, only 3 shown. Use the full 50 from the previous SQL answer.
    ],
    'CSC201' => [ // Data Structures
        ['Which data structure uses First In First Out (FIFO)?', 'Stack', 'Queue', 'Array', 'Tree', 'B'],
        ['What is the time complexity of binary search on a sorted array?', 'O(n)', 'O(log n)', 'O(n^2)', 'O(1)', 'B'],
        ['Which of these is a non-linear data structure?', 'Array', 'Linked List', 'Tree', 'Stack', 'C'],
        ['What does LIFO stand for?', 'Last In First Out', 'First In Last Out', 'Last In Last Out', 'First In First Out', 'A'],
        ['Which data structure is used for recursion?', 'Queue', 'Array', 'Stack', 'Graph', 'C'],
        // Add 45 more questions for CSC201...
    ],
    'CSC301' => [ // Database Management Systems
        ['What does SQL stand for?', 'Structured Query Language', 'Simple Query Language', 'Structured Question Language', 'Simple Question Language', 'A'],
        ['Which of the following is a type of JOIN in SQL?', 'INNER JOIN', 'OUTER JOIN', 'CROSS JOIN', 'All of the above', 'D'],
        // Add 48 more...
    ],
    'CSC401' => [ // Software Engineering
        ['What is the first phase of the Software Development Life Cycle (SDLC)?', 'Design', 'Requirements', 'Testing', 'Maintenance', 'B'],
        ['Which model is also known as the linear sequential model?', 'Waterfall', 'Spiral', 'Agile', 'V-model', 'A'],
        // Add 48 more...
    ]
];

// Prepare INSERT statement with ON DUPLICATE KEY UPDATE to avoid errors
$sql = "INSERT INTO questions (course_code, question_text, option_a, option_b, option_c, option_d, correct_answer, marks)
        VALUES (:course, :text, :a, :b, :c, :d, :correct, 1)
        ON DUPLICATE KEY UPDATE question_text = VALUES(question_text)";
$stmt = $pdo->prepare($sql);

$totalInserted = 0;
foreach ($courses as $courseCode => $questions) {
    foreach ($questions as $q) {
        // Ensure each question has exactly 6 elements
        if (count($q) != 6) {
            echo "Skipping malformed question for $courseCode: " . json_encode($q) . "<br>";
            continue;
        }
        $stmt->execute([
            ':course' => $courseCode,
            ':text'   => $q[0],
            ':a'      => $q[1],
            ':b'      => $q[2],
            ':c'      => $q[3],
            ':d'      => $q[4],
            ':correct'=> $q[5]
        ]);
        $totalInserted++;
    }
}

echo "<h3>✅ Successfully inserted/updated $totalInserted questions.</h3>";
echo "<p>Now <strong>delete this file</strong> (insert_questions.php) to prevent accidental re-runs.</p>";
?>