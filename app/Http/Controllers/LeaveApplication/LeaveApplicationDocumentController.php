<?php

namespace App\Http\Controllers\LeaveApplication;

use App\Http\Controllers\Controller;
use App\Models\LeaveApplication;
use App\Services\LeaveApplication\LeaveApplicationForm;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;

class LeaveApplicationDocumentController extends Controller
{
    public function __construct(private LeaveApplicationForm $form) {}

    /** The filled-in form, opened in the browser to print (or save). */
    public function show(LeaveApplication $leaveApplication): Response
    {
        $name = $this->form->fileName($leaveApplication);

        return response($this->form->render($leaveApplication), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition('inline', $name, Str::ascii($name)),
        ]);
    }
}
