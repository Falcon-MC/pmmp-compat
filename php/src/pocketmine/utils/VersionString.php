<?php

declare(strict_types=1);

namespace pocketmine\utils;

/** A major.minor.patch version with an optional -suffix */
class VersionString{
    private int $major;
    private int $minor;
    private int $patch;
    private string $suffix;

    public function __construct(
        private string $baseVersion,
        private bool $isDevBuild = false,
        private int $buildNumber = 0
    ){
        if(preg_match('/^(\d+)\.(\d+)\.(\d+)(?:-(.*))?$/', $baseVersion, $matches) !== 1){
            throw new \InvalidArgumentException("Invalid base version \"$baseVersion\", should contain at least 3 version digits");
        }
        $this->major = (int) $matches[1];
        $this->minor = (int) $matches[2];
        $this->patch = (int) $matches[3];
        $this->suffix = $matches[4] ?? '';
    }

    public static function isValidBaseVersion(string $baseVersion) : bool{
        return preg_match('/^\d+\.\d+\.\d+(?:-(.*))?$/', $baseVersion) === 1;
    }

    public function getNumber() : int{
        return ($this->major * 1_000_000) + ($this->minor * 1_000) + $this->patch;
    }

    public function getBaseVersion() : string{
        return $this->baseVersion;
    }

    public function getFullVersion(bool $build = false) : string{
        $version = $this->baseVersion;
        if($this->isDevBuild){
            $version .= '+dev';
            if($build && $this->buildNumber > 0){
                $version .= '.' . $this->buildNumber;
            }
        }
        return $version;
    }

    public function getMajor() : int{
        return $this->major;
    }

    public function getMinor() : int{
        return $this->minor;
    }

    public function getPatch() : int{
        return $this->patch;
    }

    public function getSuffix() : string{
        return $this->suffix;
    }

    public function getBuild() : int{
        return $this->buildNumber;
    }

    public function isDev() : bool{
        return $this->isDevBuild;
    }

    public function __toString() : string{
        return $this->getFullVersion();
    }

    /** negative when the other version is newer, positive when it is older */
    public function compare(VersionString $target, bool $diff = false) : int{
        $number = $this->getNumber();
        $other = $target->getNumber();
        if($diff){
            return $number - $other;
        }
        if($number !== $other){
            return $number > $other ? 1 : -1;
        }
        if($this->isDevBuild !== $target->isDev() || $this->buildNumber !== $target->getBuild()){
            return $this->buildNumber > $target->getBuild() ? 1 : -1;
        }
        return 0;
    }
}
