<?php
declare(strict_types=1);


use PHPUnit\Framework\TestCase;
use function PHPUnit\Framework\assertEquals;

final class ClitoolsTest extends TestCase {

  /**
   * The point of this test is just to ensure clitools is not obviously broken
   * (eg: making sure we did not mess with the path of the "require" statements)
   */
  public function test_canInvokeCliTools() {
    $exitCode = 0;
    $placeholder = array();
    exec("../clitools help", $placeholder, $exitCode);

    $this->assertEquals($exitCode, 0);
  }
}
