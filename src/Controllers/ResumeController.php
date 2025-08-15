<?php
declare(strict_types=1);

namespace App\Controllers;

require_once __DIR__ . '/../../vendor/autoload.php';

use Fpdf\Fpdf;

class ResumeController {
    public function generateResumePdf() {
        $pdf = new Fpdf();
        $pdf->AddPage();

        // Set font for the title
        // Set margins
        $pdf->SetMargins(20, 20, 20);
        $pdf->SetAutoPageBreak(true, 20);

        // Header - Name and Title
        $pdf->SetFont('Helvetica', 'B', 28);
        $pdf->SetTextColor(30, 30, 30); // Dark Grey
        $pdf->Cell(0, 15, 'Nehemia Obati', 0, 1, 'C');
        $pdf->SetFont('Helvetica', '', 14);
        $pdf->SetTextColor(80, 80, 80); // Medium Grey
        $pdf->Cell(0, 8, 'Software Developer', 0, 1, 'C');
        $pdf->Ln(10);

        // Contact Information
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->SetTextColor(50, 50, 50);
        $pdf->Cell(0, 5, 'Address: 00100, Nairobi Kenya | Email: nehemiaobati@gmail.com | Phone: +254794587533', 0, 1, 'C');
        $pdf->Ln(10);

        // Section: Summary
        $this->addSectionTitle($pdf, 'Summary');
        $pdf->SetFont('Helvetica', '', 10);
        $summary = "I'm a full-stack developer with a passion for crafting dynamic and user-friendly web experiences. Fluent in technologies from front-end languages to back-end powerhouses like Python and PHP, and proficient in cloud platforms like GCP and AWS. My expertise in Bash and Linux empowers me to manage server environments with ease. I am constantly exploring new technologies to stay at the forefront of web development and am excited to leverage my comprehensive skillset to create innovative and impactful solutions.";
        $pdf->MultiCell(0, 5, $summary);
        $pdf->Ln(8);

        // Section: Technical Skills
        $this->addSectionTitle($pdf, 'Technical Skills');
        $pdf->SetFont('Helvetica', '', 10);
        $skills = [
            "Cloud & Servers: Cloud Environments Setup (GCP, AWS, Azure), Local/Self-Hosted Server Setup, Linux/Windows Server Management, Windows IIS & Apache2 Web Server Config",
            "Programming & Web: PHP (CodeIgniter) & Python (Flask), HTML5, CSS, JavaScript, MySQL Database Management",
            "Automation & Tools: Power Automate, Bash Scripting, Git & Version Control, Manual & Automated Testing"
        ];
        foreach ($skills as $skill) {
            $pdf->SetFont('Helvetica', '', 10); // Ensure Helvetica is set for text
            $pdf->Cell(5, 5, '- ', 0, 0); // Use a simple dash for bullet point
            $pdf->MultiCell(0, 5, $skill, 0, 'L', false);
        }
        $pdf->Ln(8);

        // Section: Work Experience
        $this->addSectionTitle($pdf, 'Work Experience');
        $pdf->SetFont('Helvetica', 'B', 12);
        $pdf->Cell(0, 7, 'ICT Support', 0, 1, 'L');
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->Cell(0, 5, 'Kingsway Business Systems LTD, Kindaruma Road, Top Plaza, 2nd Floor Suite 5 | 2021-01 - Current', 0, 1, 'L');
        $pdf->Ln(2);

        // PIMIS
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Cell(0, 5, 'PIMIS (Public Investment Management Information System)', 0, 1, 'L');
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->Cell(0, 5, 'National Treasury | Project TENDER NO. TNT/025/2020-2021', 0, 1, 'L');
        $pdf->Cell(0, 5, 'https://pimisdev.treasury.go.ke/', 0, 1, 'L');
        $pdf->Ln(2);
        $pim_experience = [
            "Technical Support: Troubleshooting hardware/software, setting up environments, resolving development issues.",
            "Infrastructure Maintenance: Patching/updating software, backups, system performance monitoring.",
            "Testing: Manual/automated testing, maintaining testing environments.",
            "Documentation: Recording technical procedures, installation instructions, system specifications.",
            "Developer Training: Educating developers on new technologies and tools.",
            "Stakeholder Communication: Interfacing with project managers, business users on technical matters.",
            "Training."
        ];
        foreach ($pim_experience as $item) {
            $pdf->SetFont('Helvetica', '', 10);
            $pdf->Cell(5, 5, '- ', 0, 0);
            $pdf->MultiCell(0, 5, $item, 0, 'L', false);
        }
        $pdf->Ln(5);

        // ECIPMS
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Cell(0, 5, 'ECIPMS (Electronic County Integrated Planning Management System)', 0, 1, 'L');
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->MultiCell(0, 5, 'County Government of Kakamega | CONTRACT FOR THE SUPPLY, INSTALLATION AND COMMISSIONING OF STANDARDIZED AUTOMATED MONITORING AND EVALUATION SYSTEM. Project TENDER NO. CGKK/OG/2020/2021/01', 0, 'L', false);
        $pdf->Cell(0, 5, 'https://ecipms.kingsway.co.ke/', 0, 1, 'L');
        $pdf->Ln(2);
        $ecipms_experience = [
            "Data entry for management system.",
            "Online and hands-on support to end users.",
            "Adding and assigning levels to end users.",
            "Migrating users within the system.",
            "Manipulating data in the management system.",
            "Training."
        ];
        foreach ($ecipms_experience as $item) {
            $pdf->SetFont('Helvetica', '', 10);
            $pdf->Cell(5, 5, '- ', 0, 0);
            $pdf->MultiCell(0, 5, $item, 0, 'L', false);
        }
        $pdf->Ln(5);

        // IFMIS
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Cell(0, 5, 'IFMIS', 0, 1, 'L');
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->MultiCell(0, 5, 'National Treasury | TENDER FOR PROVISION OF ONSITE SUPPORT FOR IFMIS APPLICATIONS AND ENHANCEMENT OF IFMIS E-PROCUREMENT. Project TENDER NO. TNT/029/2019-2020', 0, 'L', false);
        $pdf->Ln(5);

        // ORACLE
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Cell(0, 5, 'ORACLE', 0, 1, 'L');
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->MultiCell(0, 5, 'National Treasury | TENDER FOR THE PROVISION OF ORACLE APPLICATION SUPPORT LICENSES. Project TENDER NO. TNT/026/2019-2020', 0, 'L', false);
        $pdf->Ln(8);

        // Section: Education & Certifications
        $this->addSectionTitle($pdf, 'Education & Certifications');
        $pdf->SetFont('Helvetica', 'B', 12);
        $pdf->Cell(0, 7, 'Computer Science', 0, 1, 'L');
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->Cell(0, 5, 'Zetech University - Ruiru | Graduated: 2021-11', 0, 1, 'L');
        $pdf->Ln(2);
        $pdf->SetFont('Helvetica', 'B', 12);
        $pdf->Cell(0, 7, 'Certificate: CCNA 1-3 & Cyber Ops', 0, 1, 'L');
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->Cell(0, 5, 'Zetech University - Ruiru | Completed: 2020-09', 0, 1, 'L');
        $pdf->Ln(8);

        // Section: Languages & Interests
        $this->addSectionTitle($pdf, 'Languages & Interests');
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Cell(30, 5, 'Languages:', 0, 0, 'L');
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->Cell(0, 5, 'English, Kiswahili', 0, 1, 'L');
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Cell(30, 5, 'Interests:', 0, 0, 'L');
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->Cell(0, 5, 'E-Sports, Basketball, Travelling', 0, 1, 'L');
        $pdf->Ln(8);

        // Section: References
        $this->addSectionTitle($pdf, 'References');
        $pdf->SetFont('Helvetica', 'B', 12);
        $pdf->Cell(0, 7, 'Kenneth Kadenge', 0, 1, 'L');
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->Cell(0, 5, 'Project Manager, Kingsway Business Service Ltd.', 0, 1, 'L');
        $pdf->Cell(0, 5, 'Tel: 0722 310 030', 0, 1, 'L');
        $pdf->Ln(4);
        $pdf->SetFont('Helvetica', 'B', 12);
        $pdf->Cell(0, 7, 'Dan Njiru', 0, 1, 'L');
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->Cell(0, 5, 'Head of Department, Zetech University', 0, 1, 'L');
        $pdf->Cell(0, 5, 'Tel: 0719 321 351', 0, 1, 'L');
        $pdf->Ln(8);

        $pdf->Output('I', 'Nehemia_Obati_Resume.pdf');
    }

    private function addSectionTitle(Fpdf $pdf, string $title) {
        $pdf->SetFont('Helvetica', 'B', 16);
        $pdf->SetFillColor(230, 230, 230); // Light Grey background for section titles
        $pdf->SetTextColor(30, 30, 30); // Dark Grey text
        $pdf->Cell(0, 10, $title, 0, 1, 'L', true);
        $pdf->Ln(5);
    }
}
