use eframe::egui;

fn main() -> eframe::Result<()> {
    let options = eframe::NativeOptions {
        renderer: eframe::Renderer::Glow,
        viewport: egui::ViewportBuilder::default()
            .with_inner_size([460.0, 400.0])
            .with_min_inner_size([340.0, 260.0]),
        ..Default::default()
    };

    eframe::run_native(
        "Rust GUI Demo",
        options,
        Box::new(|_cc| Ok(Box::new(DemoApp::default()))),
    )
}

struct DemoApp {
    name: String,
    clicks: u32,
    text_size: f32,
}

impl Default for DemoApp {
    fn default() -> Self {
        Self {
            name: String::new(),
            clicks: 0,
            text_size: 20.0,
        }
    }
}

impl eframe::App for DemoApp {
    fn ui(&mut self, ui: &mut egui::Ui, _frame: &mut eframe::Frame) {
        egui::CentralPanel::default().show(ui, |ui| {
            ui.heading("Hello from Rust");
            ui.label("A small egui/eframe window, built with cargo.");
            ui.separator();

            ui.horizontal(|ui| {
                ui.label("Name:");
                ui.text_edit_singleline(&mut self.name);
            });

            let name = self.name.trim();
            let greeting = if name.is_empty() {
                "Hello, stranger!".to_string()
            } else {
                format!("Hello, {name}!")
            };
            ui.label(egui::RichText::new(greeting).size(18.0).strong());

            ui.separator();

            if ui.button("Click me").clicked() {
                self.clicks += 1;
            }
            ui.label(format!("Button clicked {} time(s)", self.clicks));

            ui.separator();

            ui.add(egui::Slider::new(&mut self.text_size, 10.0..=40.0).text("text size"));
            ui.label(egui::RichText::new("This line follows the slider").size(self.text_size));

            ui.separator();
            ui.label(format!("egui-demo v{} running natively", env!("CARGO_PKG_VERSION")));
        });
    }
}
