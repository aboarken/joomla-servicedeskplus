<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace SdpPortal\Component\ServiceDeskPlus\Tests\Unit\Requests;

use PHPUnit\Framework\TestCase;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Requests\AttachmentValidator;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Requests\InvalidRequestInput;

final class AttachmentValidatorTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    private function file(string $content): string
    {
        $path          = tempnam(sys_get_temp_dir(), 'sdp');
        $this->files[] = $path;
        file_put_contents($path, $content);

        return $path;
    }

    private function validator(): AttachmentValidator
    {
        // Every temporary file of this test counts as uploaded.
        return new AttachmentValidator(fn (string $path): bool => \in_array($path, $this->files, true));
    }

    /** @return array{name: string, tmp_name: string, error: int, size: int} */
    private function upload(string $name, string $content): array
    {
        return ['name' => $name, 'tmp_name' => $this->file($content), 'error' => UPLOAD_ERR_OK, 'size' => 1];
    }

    /** @return list<string> */
    private function errorsFor(array $files): array
    {
        try {
            $this->validator()->validate($files);

            return [];
        } catch (InvalidRequestInput $e) {
            return $e->getKeys();
        }
    }

    public function testAcceptsAPdfWhoseContentIsAPdf(): void
    {
        $accepted = $this->validator()->validate([$this->upload('Invoice.PDF', "%PDF-1.7\n...")]);

        self::assertCount(1, $accepted);
        self::assertSame('Invoice.pdf', $accepted[0]->name);
        self::assertSame('application/pdf', $accepted[0]->contentType);
    }

    public function testRefusesAFileWhoseContentDoesNotMatchItsExtension(): void
    {
        self::assertSame(['COM_SERVICEDESKPLUS_ATTACH_ERR_TYPE'], $this->errorsFor([$this->upload('photo.png', '<?php echo 1;')]));
    }

    public function testRefusesTypesThatAreNotAllowed(): void
    {
        self::assertSame(['COM_SERVICEDESKPLUS_ATTACH_ERR_TYPE'], $this->errorsFor([$this->upload('page.html', '<html>')]));
        self::assertSame(['COM_SERVICEDESKPLUS_ATTACH_ERR_TYPE'], $this->errorsFor([$this->upload('image.svg', '<svg/>')]));
    }

    public function testRefusesOfficeFilesWithMacros(): void
    {
        $docx = "PK\x03\x04[Content_Types].xml word/document.xml word/vbaProject.bin";

        self::assertSame(['COM_SERVICEDESKPLUS_ATTACH_ERR_TYPE'], $this->errorsFor([$this->upload('report.docx', $docx)]));
        self::assertSame([], $this->errorsFor([$this->upload('report.docx', "PK\x03\x04[Content_Types].xml word/document.xml")]));
    }

    public function testRefusesFilesThatWereNotUploaded(): void
    {
        $file = ['name' => 'passwd.pdf', 'tmp_name' => '/etc/passwd', 'error' => UPLOAD_ERR_OK, 'size' => 1];

        self::assertSame(['COM_SERVICEDESKPLUS_ATTACH_ERR_UPLOAD'], $this->errorsFor([$file]));
    }

    public function testUsesTheStoredSizeNotTheClaimedOne(): void
    {
        $big         = $this->upload('big.pdf', '%PDF-' . str_repeat('x', AttachmentValidator::MAX_FILE_BYTES));
        $big['size'] = 10;

        self::assertSame(['COM_SERVICEDESKPLUS_ATTACH_ERR_SIZE'], $this->errorsFor([$big]));
    }

    public function testCountsEveryFileBeforeReadingAny(): void
    {
        $files = [];

        for ($i = 0; $i <= AttachmentValidator::MAX_FILES; $i++) {
            $files[] = ['name' => 'x.pdf', 'tmp_name' => '/nonexistent/' . $i, 'error' => UPLOAD_ERR_OK, 'size' => 1];
        }

        self::assertSame(['COM_SERVICEDESKPLUS_ATTACH_ERR_COUNT'], $this->errorsFor($files));
    }

    public function testSafeNameStripsPathsAndControlCharacters(): void
    {
        self::assertSame('passwd.pdf', AttachmentValidator::safeName('../../etc/passwd.pdf'));
        self::assertSame('evil.pdf', AttachmentValidator::safeName('C:\\Users\\x\\evil.pdf'));
        self::assertSame('a_b_.pdf', AttachmentValidator::safeName("a\"b\r\n.PDF"));
        self::assertSame('attachment.png', AttachmentValidator::safeName('...png'));
    }
}
