<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\Session\SessionInterface; // Benötigt, falls der Service direkt mit der Session interagieren würde, hier aber nicht direkt
// use Random\Randomizer; // Nur verwenden, wenn PHP >= 8.2

class CaptchaGeneratorService
{
    public  const PART_SIZE = 100; // Größe jedes der 4 Quadrate (z.B. 100x100 Pixel)
    public  const ROTATION_STEP = 45; // Drehung in Grad pro Klick
    public  const NUM_PARTS = 4; // Anzahl der CAPTCHA-Teile

    // Definieren der möglichen Farben
    private const COLORS = [
        'red' => [255, 0, 0],
        'blue' => [0, 0, 255],
        'green' => [0, 255, 0],
        'yellow' => [255, 255, 0],
        'orange' => [255, 165, 0],
        'purple' => [128, 0, 128],
    ];

    // Definieren der möglichen Formen (Dreieck und Sechseck sind komplex für 45-Grad-Rotation)
    private const SHAPES = ['circle', 'square'];

    /**
     * Generiert die CAPTCHA-Bilder und deren initiale Rotationen.
     *
     * @return array Ein Array mit 'imageParts' (Base64-kodierte Bilder) und 'initialRotations'.
     */
    public function generateCaptchaImages(): array
    {
        // Zufällige Skalierung für die Hauptform (PHP < 8.2 kompatibel)
        $mainShapeScaleFactor = mt_rand(70, 90) / 100.0; // Zufällig zwischen 0.7 und 0.9
        
        // Zufälliger Radius für den zentralen Marker-Punkt (PHP < 8.2 kompatibel)
        $markerCircleRadius = mt_rand(10, 20);

        // Zufällige Auswahl der Hauptfarbe
        $selectedColorRgb = self::COLORS[array_rand(self::COLORS)];
        $mainColor = imagecolorallocatealpha(imagecreatetruecolor(1,1), $selectedColorRgb[0], $selectedColorRgb[1], $selectedColorRgb[2], 0);
        $blackColor = imagecolorallocatealpha(imagecreatetruecolor(1,1), 0, 0, 0, 0);
        
        // Akzentfarbe (kontrastierend zur Hauptfarbe)
        $luminance = ($selectedColorRgb[0] * 0.299 + $selectedColorRgb[1] * 0.587 + $selectedColorRgb[2] * 0.114) / 255;
        $accentColorRgb = ($luminance > 0.5) ? [0, 0, 0] : [255, 255, 255];
        $accentColor = imagecolorallocatealpha(imagecreatetruecolor(1,1), $accentColorRgb[0], $accentColorRgb[1], $accentColorRgb[2], 0);

        // Zufällige Auswahl der Form
        $selectedShape = self::SHAPES[array_rand(self::SHAPES)];

        $imageParts = [];
        $initialRotations = [];

        // Die Gesamtgröße des CAPTCHAs (z.B. 200x200 für partSize=100)
        $totalCaptchaDrawingSize = self::PART_SIZE * 2; 
        // Die tatsächlich zu zeichnende Größe der Hauptform
        $scaledShapeDrawingSize = $totalCaptchaDrawingSize * $mainShapeScaleFactor;
        // Offset, um die Hauptform im Gesamt-CAPTCHA zu zentrieren
        $outerOffset = ($totalCaptchaDrawingSize - $scaledShapeDrawingSize) / 2;

        // Winkel für den Akzent-Viertelkreis des zentralen Punktes
        $whiteDotAngles = [
            ['start' => 180, 'end' => 270], // Teil 0
            ['start' => 270, 'end' => 360], // Teil 1
            ['start' => 90, 'end' => 180],  // Teil 2
            ['start' => 0, 'end' => 90],   // Teil 3
        ];
        
        // Generiere zufällige, aber unterschiedliche Startdrehungen für die 4 Teile
        $possibleRotations = [];
        for ($i = 0; $i < 8; $i++) {
            $possibleRotations[] = $i * self::ROTATION_STEP;
        }
        shuffle($possibleRotations);

        for ($i = 0; $i < self::NUM_PARTS; $i++) {
            $partImage = imagecreatetruecolor(self::PART_SIZE, self::PART_SIZE);
            imagefill($partImage, 0, 0, $blackColor); // Hintergrund des Einzelteils

            // Bestimme die Position des aktuellen Teilbildes im Gesamt-CAPTCHA-Koordinatensystem
            $partCanvasX = ($i % 2) * self::PART_SIZE;
            $partCanvasY = (int)($i / 2) * self::PART_SIZE;

            // Der Mittelpunkt der Hauptform IM GESAMT-CAPTCHA-KOORDINATENSYSTEM (zentriert)
            $mainShapeGlobalCenterX = $outerOffset + ($scaledShapeDrawingSize / 2);
            $mainShapeGlobalCenterY = $outerOffset + ($scaledShapeDrawingSize / 2);

            // Der X-Y-Zeichnungs-Ursprung für die Hauptform in Bezug auf das AKTUELLE partImage
            $drawOriginX = $mainShapeGlobalCenterX - $partCanvasX;
            $drawOriginY = $mainShapeGlobalCenterY - $partCanvasY;


            if ($selectedShape === 'circle') {
                $arcRadius = $scaledShapeDrawingSize / 2;
                
                $redArcAngles = [
                    ['start' => 180, 'end' => 270], // Teil 0
                    ['start' => 270, 'end' => 360], // Teil 1
                    ['start' => 90, 'end' => 180],  // Teil 2
                    ['start' => 0, 'end' => 90],   // Teil 3
                ];
                
                imagefilledarc($partImage, $drawOriginX, $drawOriginY, $arcRadius * 2, $arcRadius * 2, $redArcAngles[$i]['start'], $redArcAngles[$i]['end'], $mainColor, IMG_ARC_PIE);
            } elseif ($selectedShape === 'square') {
                $halfScaledSize = $scaledShapeDrawingSize / 2;

                $globalRectLeft   = $mainShapeGlobalCenterX - $halfScaledSize;
                $globalRectTop    = $mainShapeGlobalCenterY - $halfScaledSize;
                $globalRectRight  = $mainShapeGlobalCenterX + $halfScaledSize;
                $globalRectBottom = $mainShapeGlobalCenterY + $halfScaledSize;

                $rectX1 = (int) round($globalRectLeft - $partCanvasX);
                $rectY1 = (int) round($globalRectTop - $partCanvasY);
                $rectX2 = (int) round($globalRectRight - $partCanvasX);
                $rectY2 = (int) round($globalRectBottom - $partCanvasY);
                
                $rectX1 = max(0, $rectX1);
                $rectY1 = max(0, $rectY1);
                $rectX2 = min(self::PART_SIZE - 1, $rectX2);
                $rectY2 = min(self::PART_SIZE - 1, $rectY2);
                
                if ($rectX1 < $rectX2 && $rectY1 < $rectY2) {
                    imagefilledrectangle($partImage, $rectX1, $rectY1, $rectX2, $rectY2, $mainColor);
                }
            }

            // Zeichne die Trennlinien
            if ($i === 0 || $i === 2) {
                imagerectangle($partImage, self::PART_SIZE - 2, 0, self::PART_SIZE - 1, self::PART_SIZE - 1, $accentColor);
            }
            if ($i === 0 || $i === 1) {
                imagerectangle($partImage, 0, self::PART_SIZE - 2, self::PART_SIZE - 1, self::PART_SIZE - 1, $accentColor);
            }

            // Zeichne den Viertelkreis des zentralen Punktes
            $dotDrawOriginX = $mainShapeGlobalCenterX - $partCanvasX;
            $dotDrawOriginY = $mainShapeGlobalCenterY - $partCanvasY;
            
            imagefilledarc($partImage, $dotDrawOriginX, $dotDrawOriginY, $markerCircleRadius * 2, $markerCircleRadius * 2, $whiteDotAngles[$i]['start'], $whiteDotAngles[$i]['end'], $accentColor, IMG_ARC_PIE);

            // Zufällige Rotation für die Anzeige
            $randomRotation = array_shift($possibleRotations);
            $initialRotations[] = $randomRotation;

            ob_start();
            imagepng($partImage);
            $imageParts[] = 'data:image/png;base64,' . base64_encode(ob_get_clean());
            imagedestroy($partImage);
        }

        
        $captchaData = [
            'imageParts' => $imageParts,
            'initialRotations' => $initialRotations,
            'timestamp' => time(),  // ✅ Zeitstempel hinzufügen
            'attempts' => 0         // ✅ Versuchs-Counter
        ];
        
        return $captchaData;
    }
}