<div class="modal fade " id="phonePreview" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel"
     aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="exampleModalLabel">{{ __('locale.menu.Overview') }}</h5>
                <button type="button" class="btn btn-sm" data-bs-dismiss="modal"><i data-feather="x-square"
                                                                                    class="feather-24 text-danger"></i>
                </button>
            </div>
            <div class="modal-body d-flex justify-content-center">
                <div id="centered-container">
                    <!--Do Not Touch anything above this-->
                    <div class="iphone" id="iphoneLeft">
                        <div class="silence-switch outer-button"></div>
                        <div class="volume-rocker-top outer-button"></div>
                        <div class="volume-rocker-bottom outer-button"></div>
                        <div class="power-button outer-button-reversed"></div>
                        <!--...................................TOP SECTION..................................-->
                        <div class="top-section">
                            <i class="arrow left"></i>
                            <div class="top-section-time"><?php echo date('H:i:s', time()); ?></div>
                            <div class="top-section-symbols">
                                <i data-feather="bar-chart"></i>
                                <i data-feather="wifi"></i>
                                <i data-feather="battery-charging"></i>
                            </div>
                            <div class="top-section-middle">
                                <div class="speaker">
                                    <div class="front-camera"></div>
                                </div>
                                <div class="top-section-user-pic selectDisable"><i data-feather="user" class="feather-32"></i></div>
                                <div class="top-section-user-name font-weight-bold" id="senderid"></div>
                            </div>
                        </div>
                        <!--...................................MESSAGE SECTION..................................-->
                        <div class="messages-section" id="messages-left">
                            <div class="message to" id="messageto"></div>
                        </div>
                        <!--...................................KEYBOARD SECTION..................................-->
                        <div class="keyboard-section">

                            <div class="keyboard-above d-flex align-items-center justify-content-between">
                                <div class="key-add-ons">
                                    <i data-feather="plus" class="feather-24"></i>
                                    <i data-feather="mic" class="feather-24"></i>
                                </div>
                                <div class="d-flex me-1 align-items-center">
                                    <input rows="1" id="keyboardinput" class="keyboardinput auto-height"
                                           placeholder="Message" onfocus="" value="" type="text" autofocus disabled>
                                    <i data-feather="send" class="feather-24 inside-input"></i>
                                </div>
                            </div>
                            <div class="home-screen-button"></div>
                        </div>
                    </div>
                </div>
                <!--END OF FIRST IPHONE-->


                <!--END OF FIRST IPHONE-->
                <!--End of Centered Container, Do not Delete below this line-->
            </div>
            <!--Audio Elements-->
            {{-- <audio id="audio" src="images/keypress.mp3"></audio>
            <audio id="audio-sent" src="images/sent.mp3"></audio>
            <audio id="audio-backspace" src="images/backspace.mp3"></audio>
            <audio id="audio-space" src="images/space.mp3"></audio> --}}
        </div>
    </div>
</div>

