<?php
declare(strict_types=1);

require_once "../utils.php";

use PHPUnit\Framework\TestCase;
use function PHPUnit\Framework\assertEquals;

final class utilsTest extends TestCase {
  public function test_toRawText() {
    assertEquals("html text", toRawText("<body><b>html</b> text</body>"));
    assertEquals("text already raw", toRawText("text already raw"));
    assertEquals("text with self-closing tag", toRawText("text with <br />self-closing tag"));
  }
}
