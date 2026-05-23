<?php
echo "<h2>SkillVia Debug</h2>";
echo "<p>PHP is working!</p>";
echo "<p>Files in web root:</p><ul>";
foreach (scandir('/var/www/html') as $f) {
    if ($f !== '.' && $f !== '..') echo "<li>$f</li>";
}
echo "</ul>";
echo "<p>PORT env: " . getenv('PORT') . "</p>";
